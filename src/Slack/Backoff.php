<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use function max;
use function min;

/**
 * How long to wait before the next connection attempt.
 *
 * Slack drops the connection as a matter of course, so this runs on the normal path, not only after
 * a failure. The jitter keeps a fleet of reconnecting clients from lining up; it comes from an
 * injected source so that a test can pin it.
 *
 * @api
 */
final class Backoff
{
    /**
     * @param float $base       the wait after the first lost connection
     * @param float $max        the ceiling the doubling stops at
     * @param float $jitterRatio how much of the delay may be taken off, as a fraction of it
     */
    public function __construct(
        private RandomSourceInterface $random,
        #[BackoffBase]
        private float $base,
        #[BackoffMax]
        private float $max,
        #[BackoffJitterRatio]
        private float $jitterRatio,
    ) {}

    /**
     * The delay for the given attempt, counting from 1.
     *
     * The jitter is subtracted, never added, so that the result stays within the ceiling.
     */
    public function delay(int $attempt): float
    {
        $doublings = max($attempt, 1) - 1;
        // `2 ** 1000` is INF rather than an overflow, and `min` takes the ceiling from it, so a
        // long-running outage does not need its own branch.
        $delay = min($this->base * (2 ** $doublings), $this->max);

        return $delay - ($delay * $this->jitterRatio * $this->random->fraction());
    }
}
