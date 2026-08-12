<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use NaokiTsuchiya\AgentBridge\Slack\ClockInterface;
use Override;

/**
 * A clock that only moves when a test moves it.
 *
 * Without this, every case about the throttle would have to spend the throttle — and a case about
 * "not yet" cannot be written at all against a clock that runs on its own.
 *
 * @internal
 */
final class FixedClock implements ClockInterface
{
    /** @param float $seconds where the clock starts */
    public function __construct(
        private float $seconds = 1_000.0,
    ) {}

    /** Moves the clock forward by that many seconds. */
    public function advance(float $seconds): void
    {
        $this->seconds += $seconds;
    }

    /** {@inheritDoc} */
    #[Override]
    public function now(): float
    {
        return $this->seconds;
    }
}
