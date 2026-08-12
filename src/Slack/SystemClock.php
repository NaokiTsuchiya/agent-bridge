<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Override;

use function microtime;

/**
 * The clock of the machine this process runs on.
 *
 * @api
 */
final class SystemClock implements ClockInterface
{
    /** {@inheritDoc} */
    #[Override]
    public function now(): float
    {
        return microtime(as_float: true);
    }
}
