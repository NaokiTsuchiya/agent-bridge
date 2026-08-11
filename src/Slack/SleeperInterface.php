<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

/**
 * Waiting, as a dependency, so that a backoff can be observed without spending the time.
 *
 * @api
 */
interface SleeperInterface
{
    /** Yields for the given number of seconds. */
    public function sleep(float $seconds): void;
}
