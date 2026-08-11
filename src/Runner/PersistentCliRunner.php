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

use const SWOOLE_HOOK_PROC;

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
 * @mago-expect lint:cyclomatic-complexity
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

    /** How long a terminated process is given to disappear before it is left to the system. */
    private const float TERMINATION_GRACE = 2.0;

    /** @var array<string, AgentProcess> the live process of each thread, by thread id */
    private array $processes = [];

    /**
     * @param WorkingDirectoryResolver $directories where each thread's process is started
     * @param ClaudeCliSettings        $settings    which binary to run, and with which permissions
     * @param ClaudeCliEventParser     $parser      turns the binary's output into events
     */
    public function __construct(
        private WorkingDirectoryResolver $directories,
        private ClaudeCliSettings $settings = new ClaudeCliSettings(),
        private ClaudeCliEventParser $parser = new ClaudeCliEventParser(),
    ) {
        // Without this hook `proc_open` and its pipes block the whole event loop instead of the
        // one coroutine waiting on them. `Swoole\Process` is not an option: starting one inside
        // a coroutine throws `must be forked outside the coroutine` (Swoole 6.2.0). Added to the
        // flags already in place rather than replacing them, since an application may have asked
        // for more.
        Runtime::setHookFlags(Runtime::getHookFlags() | SWOOLE_HOOK_PROC);
    }

    /** @return iterable<AgentEvent> */
    #[Override]
    public function send(ThreadId $thread, string $prompt): iterable
    {
        $process = $this->processFor($thread);
        if ($process === null) {
            return self::failure("The agent could not be started for \"{$thread->value}\".");
        }

        // Written here rather than inside the generator below: a caller that abandons the events
        // still asked a question, and the turn has to be under way for `close()` to have anything
        // to wait for.
        $process->write(self::promptLine($prompt));

        return $this->turn($thread, $process, $prompt);
    }

    /** Ends the thread's process; the thread's history stays where it is. */
    #[Override]
    public function close(ThreadId $thread): void
    {
        $process = $this->processes[$thread->value] ?? null;
        if ($process === null) {
            return;
        }

        unset($this->processes[$thread->value]);

        // End of input is what makes the process stop; without it, it waits for a next turn
        // forever and the grace below would be spent on nothing.
        $process->closeInput();
        $ended = $process->awaitExit($this->settings->closeGraceSeconds);
        if (!$ended) {
            $process->terminate();
            $process->awaitExit(self::TERMINATION_GRACE);
        }

        $process->release();
    }

    /**
     * Reads one turn, replacing the process underneath when it turns out to have been a wrong guess.
     *
     * @return Generator<int, AgentEvent>
     */
    private function turn(ThreadId $thread, AgentProcess $process, string $prompt): Generator
    {
        $restarted = false;
        while (true) {
            $line = $process->nextLine();
            $endedEarly = $line === null && !$restarted && self::fallbackWanted($process, null);
            if ($line === null && !$endedEarly) {
                yield new AgentError($process->failureMessage());

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

                    yield $event;

                    if ($completed !== null) {
                        return;
                    }
                }
            }

            if (!$fallback) {
                continue;
            }

            $next = $this->restart($thread, $prompt);
            if ($next === null) {
                yield new AgentError("The agent could not be started for \"{$thread->value}\".");

                return;
            }

            $process = $next;
            $restarted = true;
        }
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
        $existing = $this->processes[$thread->value] ?? null;
        $alive = $existing !== null && $existing->isRunning();
        if ($alive) {
            return $existing;
        }

        $existing?->release();

        return $this->launch($thread, HistoryStart::Continuing);
    }

    /** Starts the second process of a turn and gives it the prompt the first one never answered. */
    private function restart(ThreadId $thread, string $prompt): ?AgentProcess
    {
        $previous = $this->processes[$thread->value] ?? null;
        $previous?->release();
        unset($this->processes[$thread->value]);

        $process = $this->launch($thread, HistoryStart::Beginning);
        $process?->write(self::promptLine($prompt));

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

        $process = AgentProcess::start($command, $this->directories->resolve($thread), $start);
        if ($process === null) {
            unset($this->processes[$thread->value]);

            return null;
        }

        $this->processes[$thread->value] = $process;

        return $process;
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
