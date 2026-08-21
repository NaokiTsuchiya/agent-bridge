<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

/**
 * How long to wait before the next connection attempt, and how much of it is jitter.
 *
 * Split from {@see ConnectionSettings} rather than folded into it: with the arithmetic's three
 * values added to the other four, a single settings object would carry more parameters than this
 * project's own style allows.
 *
 * @api
 */
final readonly class BackoffSettings
{
    /**
     * @param float $base        the wait after the first lost connection ({@see Backoff})
     * @param float $max         the ceiling the doubling stops at ({@see Backoff})
     * @param float $jitterRatio how much of the delay may be taken off, as a fraction of it
     *                           ({@see Backoff})
     */
    public function __construct(
        public float $base = 1.0,
        public float $max = 30.0,
        public float $jitterRatio = 0.5,
    ) {}
}
