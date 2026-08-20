<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use Generator;
use NaokiTsuchiya\AgentBridge\Event\AgentEvent;
use NaokiTsuchiya\AgentBridge\Event\ClaudeCliEventParser;
use NaokiTsuchiya\AgentBridge\Event\TurnCompleted;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;
use Swoole\Runtime;

use const SWOOLE_HOOK_PROC;
use const SWOOLE_HOOK_STREAM_FUNCTION;

/**
 * An {@see AgentRunner} that starts a `claude` per turn and lets it end with the turn.
 *
 * The second implementation of the execution layer, and the reason the interface is shaped the way
 * it is: nothing here is kept between turns — no process, no map, no pool — and a caller cannot
 * tell from the declaration which of the two it is talking to. It is also the fallback the design
 * asks for should keeping processes alive turn out to be the wrong bet (docs/poc-design.md 4.4).
 *
 * **The prompt travels on the command line and stdin is closed at once**
 * ({@see ClaudeCliCommand::oneShot()}). Left open, the real binary spends three seconds waiting for
 * input that will never come and says so on its diagnostics stream, every single turn.
 *
 * **Which session a thread continues is derived, not stored**, exactly as in
 * {@see PersistentCliRunner}: `--resume` first, and a second run under `--session-id` when the
 * derived session was not there ({@see MissingSession}). The one thing this implementation cannot
 * borrow is how the resident one tells a lost session from a turn that merely failed — that answer
 * reads "the process is still alive", and here the process always ends. So a resumed session whose
 * first turn fails without saying anything is retried once under `--session-id`, Claude Code
 * refuses the id it already has, and the caller is told the turn failed. Wrong error, never a wrong
 * answer, and only on a turn that had already failed.
 *
 * Only usable from inside a coroutine: a thread's turn is serialized with a channel
 * ({@see TurnLocks}), which is also what keeps two turns out of one worktree.
 *
 * @api
 */
final class SpawnCliRunner implements AgentRunner
{
    /**
     * @param WorkingDirectoryResolver $directories where each thread's process is started
     * @param ClaudeCliCommand         $command     how the binary is asked to answer one prompt
     * @param ClaudeCliEventParser     $parser      turns the binary's output into events
     * @param TurnLocks                $locks       one mutex per thread, held for as long as that
     *                                              thread's turn lasts
     * @param float                    $turnSeconds how long a turn may go without reaching its
     *                                              completion event. The value itself rather than
     *                                              the settings it is written in: the rest of
     *                                              {@see LifecycleSettings} is about processes that
     *                                              are kept between turns, and this runner keeps none
     */
    public function __construct(
        private WorkingDirectoryResolver $directories,
        private ClaudeCliCommand $command,
        private ClaudeCliEventParser $parser,
        private TurnLocks $locks,
        private float $turnSeconds,
    ) {
        // The same two hooks {@see PersistentCliRunner} turns on, and for the same reasons: without
        // SWOOLE_HOOK_PROC the pipes block the event loop instead of the one coroutine reading
        // them, and without SWOOLE_HOOK_STREAM_FUNCTION the `stream_select` every turn waits in
        // freezes every other thread.
        Runtime::setHookFlags(Runtime::getHookFlags() | SWOOLE_HOOK_PROC | SWOOLE_HOOK_STREAM_FUNCTION);
    }

    /**
     * {@inheritDoc}
     *
     * Written as a generator so that the lock is taken when the turn actually begins and given back
     * in one place. The resident runner can afford to take it before returning its events, because
     * its `close()` settles a turn nobody read; here `close()` has nothing to settle, so the
     * `finally` below is the only thing that can free the thread.
     *
     * @return iterable<AgentEvent>
     */
    #[Override]
    public function send(ThreadId $thread, string $prompt): iterable
    {
        $this->locks->acquire($thread->value);

        try {
            yield from $this->turn($thread, $prompt);
        } finally {
            $this->locks->release($thread->value);
        }
    }

    /**
     * {@inheritDoc}
     *
     * Nothing is held between turns, so there is nothing to give up and calling this twice — or
     * never — makes no difference to anything.
     */
    #[Override]
    public function close(ThreadId $thread): void {}

    /**
     * {@inheritDoc}
     *
     * Nothing is kept between turns, so the answer is always zero.
     */
    #[Override]
    public function liveProcesses(): int
    {
        return 0;
    }

    /**
     * One turn, which is one process, or two when the derived session was not there.
     *
     * @return Generator<int, AgentEvent> the turn, ending with the event that says it is over
     */
    private function turn(ThreadId $thread, string $prompt): Generator
    {
        $turn = new Turn($thread, $this->turnSeconds);

        // The two ways a process may relate to the thread's history, in the order they are tried.
        // That the list ends is what makes "the guess is corrected once" structural rather than a
        // flag somebody has to remember to set.
        foreach ([HistoryStart::Continuing, HistoryStart::Beginning] as $attempt => $start) {
            $process = $this->launch($thread, $start, $prompt);
            if ($process === null) {
                yield TurnFailure::notStarted($thread);

                return;
            }

            $answer = $this->answered($process, $turn, restarted: $attempt > 0);

            yield from $answer;

            $again = $answer->getReturn();
            if (!$again) {
                return;
            }
        }
    }

    /**
     * One process's share of the turn, after which that process is gone whatever happened.
     *
     * @param bool $restarted whether this is already the turn's second process
     *
     * @return Generator<int, AgentEvent, mixed, bool> what {@see read()} concluded
     */
    private function answered(AgentProcess $process, Turn $turn, bool $restarted): Generator
    {
        $events = $this->read($process, $turn, $restarted);

        try {
            yield from $events;
        } finally {
            // Reached by a caller that stopped reading too, since abandoning a generator runs this
            // on the way out — which is the whole reason the release lives here.
            //
            // Asked to stop rather than waited on: its input reached its end before the turn began,
            // so a child still here is not one that is about to finish reading — it is one that is
            // not leaving.
            ProcessRelease::kill($process);
        }

        return $events->getReturn();
    }

    /**
     * @param bool $restarted whether this is already the turn's second process
     *
     * @return Generator<int, AgentEvent, mixed, bool> true when what came back says the guessed
     *                                                 session was not there, so the same prompt
     *                                                 wants a second process
     */
    private function read(AgentProcess $process, Turn $turn, bool $restarted): Generator
    {
        while (true) {
            $line = $process->nextLine($turn->left());
            if ($line === null) {
                $error = TurnFailure::of($turn, $process, $restarted);
                if ($error === null) {
                    return true;
                }

                yield $error;

                return false;
            }

            foreach ($this->parser->parse($line) as $event) {
                $completed = $event instanceof TurnCompleted ? $event : null;
                // The judgment lives in {@see TurnEvents::isWrongGuess()}, the one place it
                // exists.
                $wrongGuess = TurnEvents::isWrongGuess($process, $completed, $restarted);
                if ($wrongGuess) {
                    return true;
                }

                $process->recordEmission();

                yield $event;

                if ($completed !== null) {
                    return false;
                }
            }
        }
    }

    /** @return AgentProcess|null null when the binary could not be started */
    private function launch(ThreadId $thread, HistoryStart $start, string $prompt): ?AgentProcess
    {
        $command = $this->command->oneShot($thread, $start, $prompt);
        $cwd = $this->directories->resolve($thread);
        $process = AgentProcess::start($command, $cwd, $start);

        // End of input before a single line is read: this run was given everything it needs on its
        // command line, and the binary waits for stdin it will never get otherwise.
        $process?->closeInput();

        return $process;
    }
}
