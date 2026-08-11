<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use NaokiTsuchiya\AgentBridge\Thread\ThreadId;

use function microtime;

/**
 * One turn in flight, and the single place that says whether it is over.
 *
 * Two things end a turn and they do not know about each other: the events running out (a
 * completion, a failure, a deadline) and {@see PersistentCliRunner::close()} taking the process
 * away from underneath. Both call {@see finish()}, only the first one gets `true`, and that is
 * what makes releasing the thread's lock exactly-once instead of once per way of ending.
 *
 * @api
 */
final class Turn
{
    /** Whether this turn has already been settled, by whichever side got there first. */
    private bool $finished = false;

    /** When the turn must have reached its completion event, as an absolute microtime value. */
    private float $deadline;

    /**
     * @param ThreadId $thread    whose turn this is
     * @param float    $allowance how long the turn may go without reaching its completion event.
     *                            Kept as given, not only as a deadline, because it is what the
     *                            caller is told when the turn runs out of it
     */
    public function __construct(
        public ThreadId $thread,
        public float $allowance,
    ) {
        $this->deadline = microtime(true) + $allowance;
    }

    /** @return bool true for the caller that ended the turn, false for every later one */
    public function finish(): bool
    {
        if ($this->finished) {
            return false;
        }

        $this->finished = true;

        return true;
    }

    /**
     * @return bool whether the turn is over, however it ended
     *
     * @mutation-free
     */
    public function isFinished(): bool
    {
        return $this->finished;
    }

    /**
     * @return float how long the turn has left, never below zero — which is how a read is told
     *               how long it may wait
     *
     * @mutation-free
     */
    public function left(): float
    {
        $left = $this->deadline - microtime(true);

        return $left > 0.0 ? $left : 0.0;
    }
}
