<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use NaokiTsuchiya\AgentBridge\Slack\RandomSourceInterface;
use Override;

/**
 * The same fraction every time, so that a jittered delay is a number a test can name.
 *
 * @internal
 */
final class FixedRandomSource implements RandomSourceInterface
{
    /** @param float $fraction what every call answers with */
    public function __construct(
        private float $fraction = 0.0,
    ) {}

    /** The same value on every call; a backoff built on it has one answer per attempt. */
    #[Override]
    public function fraction(): float
    {
        return $this->fraction;
    }
}
