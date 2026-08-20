<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Override;

/**
 * Keeps every requested wait instead of taking it, which is what makes a backoff observable.
 *
 * @internal
 */
final class RecordingSleeper implements SleeperInterface
{
    /** @var list<float> the seconds asked for, in order */
    public array $delays = [];

    /** The wait is recorded rather than taken, which is the whole point of the double. */
    #[Override]
    public function sleep(float $seconds): void
    {
        $this->delays[] = $seconds;
    }
}
