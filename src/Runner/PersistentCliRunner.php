<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use NaokiTsuchiya\AgentBridge\Event\AgentEvent;
use NaokiTsuchiya\AgentBridge\Event\ClaudeCliEventParser;
use NaokiTsuchiya\AgentBridge\Thread\ThreadDerivation;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;
use Swoole\Runtime;

use const SWOOLE_HOOK_PROC;
use const SWOOLE_HOOK_STREAM_FUNCTION;

/**
 * An {@see AgentRunner} that keeps one `claude` process per thread and talks to it over stdin.
 *
 * The process is a cache, not the truth: Claude Code keeps the transcript itself, so a process
 * that died — reaped, crashed, or closed — costs nothing but the time to start another one, and
 * the next turn quietly does exactly that. Which processes are kept and which are given up is
 * {@see ProcessPool}'s business; reading one turn out of one process is {@see TurnEvents}'.
 *
 * **Which session a thread continues is derived, not stored** ({@see ThreadDerivation::sessionId()}).
 * There is no lookup table to keep, but the derived id may name a session that Claude Code no
 * longer has, and the two flags that could be passed refuse opposite things ({@see MissingSession}).
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
 * @api
 */
final class PersistentCliRunner implements AgentRunner
{
    /** @var array<string, Turn> the turn each thread is in the middle of, by thread id */
    private array $turns = [];

    /**
     * @param ProcessRecipe        $recipe      where each thread's process is started, and with
     *                                          what arguments
     * @param ClaudeCliEventParser $parser      turns the binary's output into events
     * @param TurnLocks            $locks       one mutex per thread, held for as long as that
     *                                          thread's turn lasts
     * @param ProcessPool          $pool        the processes, and the rules by which they are
     *                                          reclaimed
     * @param float                $turnSeconds how long a turn may go without reaching its
     *                                          completion event
     */
    public function __construct(
        private ProcessRecipe $recipe,
        private ClaudeCliEventParser $parser,
        private TurnLocks $locks,
        private ProcessPool $pool,
        #[TurnSeconds]
        private float $turnSeconds,
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
    }

    /** @return iterable<AgentEvent> */
    #[Override]
    public function send(ThreadId $thread, string $prompt): iterable
    {
        // Taken before anything is started, and given back by whoever ends the turn: a second
        // send on this thread parks right here until the first turn is over.
        $this->locks->acquire($thread->value);
        $turn = new Turn($thread, $this->turnSeconds);
        $this->turns[$thread->value] = $turn;

        $process = $this->processFor($thread);
        if ($process === null) {
            $this->settle($turn);

            return TurnFailure::only(TurnFailure::notStarted($thread));
        }

        $this->pool->beginTurn($thread);

        // Written here rather than inside the events below: a caller that abandons them still
        // asked a question, and the turn has to be under way for `close()` to have anything to
        // wait for.
        $process->write(ClaudeCliCommand::prompt($prompt));

        $events = new TurnEvents(
            $this->parser,
            $this->pool,
            $turn,
            fn(): ?AgentProcess => $this->restart($thread, $prompt),
            function (?AgentProcess $answered) use ($turn): void {
                $this->settle($turn, $answered);
            },
        );

        return $events->all($process);
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
     * Ends the turn once, whichever side of it got here first.
     *
     * @param AgentProcess|null $process the process that answered it, when there is one that may
     *                                   have died on the way
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
        $process->write(ClaudeCliCommand::prompt($prompt));

        return $process;
    }

    /** @return AgentProcess|null null when the binary could not be started */
    private function launch(ThreadId $thread, HistoryStart $start): ?AgentProcess
    {
        return $this->pool->admit($thread, fn(): ?AgentProcess => $this->recipe->start($thread, $start));
    }
}
