<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

/**
 * The two ways a child is let go of, both of them ending in it being collected.
 *
 * Collecting is not optional and there is only one place that does it: no SIGCHLD handler is
 * installed anywhere, precisely so that nothing races with the wait inside
 * {@see AgentProcess::release()} — two collectors of one child leave one of them collecting a
 * stranger, and a child nobody collects stays in the process table as a defunct entry.
 *
 * Both ways yield while they wait, so the caller must have taken the process out of
 * {@see ProcessTable} first.
 *
 * @api
 */
final readonly class ProcessRelease
{
    /** How long a terminated child is given to disappear before it is left to the system. */
    private const float TERMINATION_GRACE = 2.0;

    /**
     * @param float $closeGraceSeconds how long a child is given to end by itself once its input
     *                                 is closed. A turn in flight is what makes this take time
     */
    public function __construct(
        private float $closeGraceSeconds,
    ) {}

    /** Asks the child to finish and stop, killing it only if it will not. */
    public function stop(AgentProcess $process): void
    {
        // End of input is what makes a `claude` finish and exit; without it, it waits for a next
        // turn forever and the grace below would be spent on nothing.
        $process->closeInput();
        $ended = $process->awaitExit($this->closeGraceSeconds);
        if (!$ended) {
            $this->kill($process);

            return;
        }

        $process->release();
    }

    /** Ends the child where it stands, for when waiting on it is what went wrong. */
    public function kill(AgentProcess $process): void
    {
        $process->terminate();
        $process->awaitExit(self::TERMINATION_GRACE);
        $process->release();
    }
}
