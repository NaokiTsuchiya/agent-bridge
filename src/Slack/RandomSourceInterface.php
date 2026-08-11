<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

/**
 * Randomness, as a dependency, so that a jittered backoff can be asserted on exactly.
 *
 * @api
 */
interface RandomSourceInterface
{
    /** A value in `[0.0, 1.0)`. */
    public function fraction(): float;
}
