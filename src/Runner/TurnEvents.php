<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use Closure;
use Generator;
use NaokiTsuchiya\AgentBridge\Event\AgentEvent;
use NaokiTsuchiya\AgentBridge\Event\ClaudeCliEventParser;
use NaokiTsuchiya\AgentBridge\Event\TurnCompleted;

/**
 * One turn's worth of events, read line by line from the process answering it.
 *
 * This is a state machine over what comes back from one read: a line to parse, nothing because
 * the deadline passed, nothing because the process ended, or a completion that says the session
 * was never there. What it may *not* decide is where a replacement process comes from and what
 * ending a turn costs — those belong to whoever owns the pool and the thread's lock, and arrive
 * as the two closures below.
 *
 * @api
 */
final class TurnEvents
{
    /**
     * @param ClaudeCliEventParser $parser  turns one line of output into events
     * @param ProcessPool          $pool    asked to give up a process that will not answer
     * @param Turn                 $turn    the turn being read, which carries its own deadline
     * @param Closure(): (AgentProcess|null) $restart hands back the turn's second process, already
     *                                                given the prompt, or null when none could be
     *                                                started
     * @param Closure(AgentProcess|null): void $end   ends the turn — releases the thread, and
     *                                                gives up the process handed to it if it has
     *                                                died. Called before any terminal event
     *                                                leaves, because a caller is free to stop
     *                                                reading as soon as it has one
     */
    public function __construct(
        private ClaudeCliEventParser $parser,
        private ProcessPool $pool,
        private Turn $turn,
        private Closure $restart,
        private Closure $end,
    ) {}

    /**
     * @param AgentProcess $process the process the prompt was written to
     *
     * @return Generator<int, AgentEvent> the turn, ending with the event that says it is over
     */
    public function all(AgentProcess $process): Generator
    {
        $restarted = false;
        while (true) {
            // Set by close(), which took the process away while this generator was suspended.
            // Reading from it now would be reading from a handle that has been collected.
            if ($this->turn->isFinished()) {
                return;
            }

            $line = $process->nextLine($this->turn->left());
            $ending = $this->ending($process, $line, $restarted);
            if ($ending !== null) {
                yield $ending;

                return;
            }

            // Nothing was read and the turn did not end: the process was `--resume`d into a
            // session that is not there, and the prompt goes to a second one.
            $fallback = true;
            if ($line !== null) {
                $fallback = yield from $this->parse($process, $line, $restarted);
            }

            if (!$fallback) {
                continue;
            }

            $next = ($this->restart)();
            if ($next === null) {
                ($this->end)(null);

                yield TurnFailure::notStarted($this->turn->thread);

                return;
            }

            $process = $next;
            $restarted = true;
        }
    }

    /**
     * @param string $line      one line of the binary's output
     * @param bool   $restarted whether this is already the turn's second process
     *
     * @return Generator<int, AgentEvent, mixed, bool> true when the line says the guessed session
     *                                                 was not there, so the turn wants a second
     *                                                 process
     */
    private function parse(AgentProcess $process, string $line, bool $restarted): Generator
    {
        foreach ($this->parser->parse($line) as $event) {
            $completed = $event instanceof TurnCompleted ? $event : null;
            $wrongGuess = $completed !== null && !$restarted && MissingSession::suspected($process, $completed);
            if ($wrongGuess) {
                return true;
            }

            $process->emitted = true;

            if ($completed !== null) {
                ($this->end)($process);
            }

            yield $event;

            if ($completed !== null) {
                return false;
            }
        }

        return false;
    }

    /**
     * Whether the read that just came back ends the turn, and with what.
     *
     * @param string|null $line      what was read, or null for a deadline or an ended process
     * @param bool        $restarted whether this is already the turn's second process
     *
     * @return AgentEvent|null null when the turn goes on, including when it is about to be handed
     *                         to a second process
     */
    private function ending(AgentProcess $process, ?string $line, bool $restarted): ?AgentEvent
    {
        if ($line !== null) {
            return null;
        }

        $error = TurnFailure::of($this->turn, $process, $restarted);
        if ($error === null) {
            return null;
        }

        // Given up rather than asked to stop: whichever of the two failures this is, the process
        // is either gone already or is not answering, so there is nothing to wait for.
        $this->pool->discard($this->turn->thread);
        ($this->end)(null);

        return $error;
    }
}
