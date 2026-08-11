<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use Generator;
use NaokiTsuchiya\AgentBridge\Event\AgentError;
use NaokiTsuchiya\AgentBridge\Event\AgentEvent;
use NaokiTsuchiya\AgentBridge\Event\ClaudeCliEventParser;
use NaokiTsuchiya\AgentBridge\Event\TurnCompleted;
use NaokiTsuchiya\AgentBridge\Thread\ThreadDerivation;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;
use Swoole\Runtime;

use function implode;
use function json_encode;
use function microtime;

use const SWOOLE_HOOK_PROC;
use const SWOOLE_HOOK_STREAM_FUNCTION;

/**
 * An {@see AgentRunner} that keeps one `claude` process per thread and talks to it over stdin.
 *
 * The process is a cache, not the truth: Claude Code keeps the transcript itself, so a process
 * that died — reaped, crashed, or closed — costs nothing but the time to start another one, and
 * the next turn quietly does exactly that.
 *
 * **Which session a thread continues is derived, not stored** ({@see ThreadDerivation::sessionId()}).
 * There is no lookup table to keep, but the derived id may name a session that Claude Code no
 * longer has, and `--session-id` refuses to reuse an id while `--resume` refuses to invent one.
 * So a thread's first process is started with `--resume` on the chance that the history is still
 * there, and when that process ends without having produced anything, the same prompt is handed
 * to a second one started with `--session-id`. A session that has aged out therefore turns into
 * a new one without anybody having to notice.
 *
 * **A thread answers one turn at a time** ({@see TurnLocks}), which is also what keeps two turns
 * out of one worktree; different threads run at the same time and are meant to. Interrupting a
 * turn in flight is not offered at all.
 *
 * Only usable from inside a coroutine: both the waiting for a thread's turn and the waiting for
 * room in the pool are done with channels.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 *
 * @api
 */
final class PersistentCliRunner implements AgentRunner
{
    /**
     * How long a failed first turn is given to be the end of the process.
     *
     * Only ever waited on the ambiguous line: a process started with `--resume` that reports a
     * failed turn having produced nothing else. Claude Code answers a session it cannot find
     * exactly that way and then exits, but a session it *did* find can also fail its first turn
     * and live on — and treating that one as missing would start a second process on an id that
     * is taken, which fails outright.
     *
     * The wait has to outlast the gap between that result line and the exit which explains it:
     * 0.62s measured against Claude Code 2.1.223, so this is a wide margin over what was seen,
     * paid only on this one line.
     */
    private const float EXIT_GRACE = 2.0;

    /** The processes, and the rules by which they are reclaimed. */
    private ProcessPool $pool;

    /** One mutex per thread, held for as long as that thread's turn lasts. */
    private TurnLocks $locks;

    /** @var array<string, Turn> the turn each thread is in the middle of, by thread id */
    private array $turns = [];

    /** How long a turn may go without reaching its completion event. */
    private float $turnSeconds;

    /**
     * @param WorkingDirectoryResolver $directories where each thread's process is started
     * @param ClaudeCliSettings        $settings    which binary to run, and with which permissions
     * @param ClaudeCliEventParser     $parser      turns the binary's output into events
     * @param LifecycleSettings        $limits      how long a process may live and how many there
     *                                              may be
     */
    public function __construct(
        private WorkingDirectoryResolver $directories,
        private ClaudeCliSettings $settings = new ClaudeCliSettings(),
        private ClaudeCliEventParser $parser = new ClaudeCliEventParser(),
        LifecycleSettings $limits = new LifecycleSettings(),
    ) {
        // Without these hooks `proc_open` and its pipes block the whole event loop instead of the
        // one coroutine waiting on them. `Swoole\Process` is not an option: starting one inside
        // a coroutine throws `must be forked outside the coroutine` (Swoole 6.2.0). Added to the
        // flags already in place rather than replacing them, since an application may have asked
        // for more.
        //
        // `SWOOLE_HOOK_STREAM_FUNCTION` is what makes `stream_select` — the one call every turn
        // waits in ({@see ProcessOutput}) — give up the processor. Measured on Swoole 6.2.0 with
        // only `SWOOLE_HOOK_PROC`: a coroutine that slept 0.2s next to a one-second `stream_select`
        // ran after 1.008s, i.e. one thread's wait froze every other thread. With this flag the
        // same sleeper ran at 0.203s. Threads are meant to run at the same time, so this is not
        // an optimization.
        Runtime::setHookFlags(Runtime::getHookFlags() | SWOOLE_HOOK_PROC | SWOOLE_HOOK_STREAM_FUNCTION);

        $this->pool = new ProcessPool($limits, $settings->closeGraceSeconds);
        $this->locks = new TurnLocks();
        $this->turnSeconds = $limits->turnSeconds;
    }

    /** @return iterable<AgentEvent> */
    #[Override]
    public function send(ThreadId $thread, string $prompt): iterable
    {
        // Taken before anything is started, and given back by whoever ends the turn: a second
        // send on this thread parks right here until the first turn is over.
        $this->locks->acquire($thread->value);
        $turn = new Turn($thread, microtime(true) + $this->turnSeconds);
        $this->turns[$thread->value] = $turn;

        $process = $this->processFor($thread);
        if ($process === null) {
            $this->settle($turn);

            return self::failure("The agent could not be started for \"{$thread->value}\".");
        }

        $this->pool->beginTurn($thread);

        // Written here rather than inside the generator below: a caller that abandons the events
        // still asked a question, and the turn has to be under way for `close()` to have anything
        // to wait for.
        $process->write(self::promptLine($prompt));

        return $this->turn($turn, $process, $prompt);
    }

    /** Ends the thread's process; the thread's history stays where it is. */
    #[Override]
    public function close(ThreadId $thread): void
    {
        // The process goes first and the turn is settled after it: releasing the lock earlier
        // would let a next turn start a process for this thread while this one is still going.
        $this->pool->drop($thread);

        $turn = $this->turns[$thread->value] ?? null;
        if ($turn === null) {
            return;
        }

        $this->settle($turn);
    }

    /** @return int how many child processes are being held right now, at most the configured limit */
    public function liveProcesses(): int
    {
        return $this->pool->count();
    }

    /**
     * Reads one turn, replacing the process underneath when it turns out to have been a wrong guess.
     *
     * @return Generator<int, AgentEvent>
     */
    private function turn(Turn $turn, AgentProcess $process, string $prompt): Generator
    {
        $restarted = false;
        while (true) {
            // Set by close(), which took the process away while this generator was suspended.
            // Reading from it now would be reading from a handle that has been collected.
            if ($turn->isFinished()) {
                return;
            }

            $line = $process->nextLine($this->left($turn));
            $silent = $line === null && !$process->outputEnded();
            if ($silent) {
                yield $this->timeOut($turn);

                return;
            }

            $endedEarly = $line === null && !$restarted && self::fallbackWanted($process, null);
            if ($line === null && !$endedEarly) {
                yield $this->died($turn, $process);

                return;
            }

            $fallback = $line === null;
            if ($line !== null) {
                foreach ($this->parser->parse($line) as $event) {
                    $completed = $event instanceof TurnCompleted ? $event : null;
                    $wrongGuess = $completed !== null && !$restarted && self::fallbackWanted($process, $completed);
                    if ($wrongGuess) {
                        $fallback = true;
                        break;
                    }

                    $process->emitted = true;

                    if ($completed !== null) {
                        // Settled before the event leaves, not after: a caller is free to stop
                        // reading once it has the boundary, and the code after a `yield` it never
                        // comes back to does not run.
                        $this->settle($turn, $process);

                        yield $event;

                        return;
                    }

                    yield $event;
                }
            }

            if (!$fallback) {
                continue;
            }

            $next = $this->restart($turn->thread, $prompt);
            if ($next === null) {
                $this->settle($turn);

                yield new AgentError("The agent could not be started for \"{$turn->thread->value}\".");

                return;
            }

            $process = $next;
            $restarted = true;
        }
    }

    /** @return AgentError what the caller is told when the turn ran out of time */
    private function timeOut(Turn $turn): AgentError
    {
        // Killed rather than asked to stop: what makes this a timeout is that it is not answering.
        $this->pool->discard($turn->thread);
        $this->settle($turn);

        return new AgentError(
            "The agent did not finish the turn for \"{$turn->thread->value}\" within {$this->turnSeconds} seconds.",
        );
    }

    /** @return AgentError what the caller is told when the process ended in the middle of the turn */
    private function died(Turn $turn, AgentProcess $process): AgentError
    {
        // Asked before the process is let go of: afterwards its exit code and diagnostics are gone.
        $error = new AgentError($process->failureMessage());
        $this->pool->discard($turn->thread);
        $this->settle($turn);

        return $error;
    }

    /**
     * Ends the turn once, whichever side of it got here first.
     *
     * @param AgentProcess|null $process the process that answered it, when there is one still to
     *                                   be given up on failure
     */
    private function settle(Turn $turn, ?AgentProcess $process = null): void
    {
        $first = $turn->finish();
        if (!$first) {
            return;
        }

        $key = $turn->thread->value;
        $current = $this->turns[$key] ?? null;
        if ($current === $turn) {
            unset($this->turns[$key]);
        }

        $ended = $process !== null && !$process->isRunning();
        if ($ended) {
            $this->pool->discard($turn->thread);
        }

        $this->pool->endTurn($turn->thread);
        $this->locks->release($key);
    }

    /** @return float how long the turn still has, never below zero */
    private function left(Turn $turn): float
    {
        $left = $turn->deadline - microtime(true);

        return $left > 0.0 ? $left : 0.0;
    }

    /**
     * Whether what just happened says the guessed session was not there.
     *
     * @param TurnCompleted|null $completed the turn boundary that raised the question, or null
     *                                      when the process ended without one
     */
    private static function fallbackWanted(AgentProcess $process, ?TurnCompleted $completed): bool
    {
        // Anything already handed to the caller settles it: this process was talking to a real
        // session, so a later death is a death, not a wrong guess, and re-sending the prompt to
        // a fresh process would repeat what the caller has seen.
        if ($process->start !== HistoryStart::Continuing || $process->emitted) {
            return false;
        }

        if ($completed === null) {
            return true;
        }

        return !$completed->success && $process->awaitExit(self::EXIT_GRACE);
    }

    /** @return AgentProcess|null null when no process could be started */
    private function processFor(ThreadId $thread): ?AgentProcess
    {
        $existing = $this->pool->live($thread);
        if ($existing !== null) {
            return $existing;
        }

        return $this->launch($thread, HistoryStart::Continuing);
    }

    /** Starts the second process of a turn and gives it the prompt the first one never answered. */
    private function restart(ThreadId $thread, string $prompt): ?AgentProcess
    {
        $this->pool->discard($thread);

        $process = $this->launch($thread, HistoryStart::Beginning);
        if ($process === null) {
            return null;
        }

        $this->pool->beginTurn($thread);
        $process->write(self::promptLine($prompt));

        return $process;
    }

    /** @return AgentProcess|null null when the binary could not be started */
    private function launch(ThreadId $thread, HistoryStart $start): ?AgentProcess
    {
        $command = [
            $this->settings->binary,
            '-p',
            '--input-format',
            'stream-json',
            '--output-format',
            'stream-json',
            '--verbose',
            // Without this the reply arrives in one piece at the end of the turn.
            '--include-partial-messages',
            '--allowedTools',
            implode(',', $this->settings->allowedTools),
            $start->value,
            ThreadDerivation::sessionId($thread),
        ];
        $cwd = $this->directories->resolve($thread);

        return $this->pool->admit($thread, static fn(): ?AgentProcess => AgentProcess::start($command, $cwd, $start));
    }

    /** @return Generator<int, AgentEvent> */
    private static function failure(string $message): Generator
    {
        yield new AgentError($message);
    }

    /** @return string one line of `stream-json` input, newline included */
    private static function promptLine(string $prompt): string
    {
        $line = json_encode([
            'type' => 'user',
            'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => $prompt]]],
        ]);

        return ($line === false ? '{}' : $line) . "\n";
    }
}
