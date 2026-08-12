<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

/**
 * The passing of time, as a dependency, so that a throttle can be driven without spending it.
 *
 * Separate from {@see SleeperInterface}: waiting and knowing how long has passed are two different
 * things here, and a streamed reply only ever needs the second one — it never sleeps, it decides
 * whether enough time has gone by to send again.
 *
 * @api
 */
interface ClockInterface
{
    /** @return float now, in seconds, on a scale only differences are read from */
    public function now(): float;
}
