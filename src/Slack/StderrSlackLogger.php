<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Override;

use function error_log;

/**
 * The default log, which puts one line on the process' error stream.
 *
 * @api
 */
final class StderrSlackLogger implements SlackLoggerInterface
{
    /** `error_log` rather than a write to `php://stderr`, so that a configured `error_log` wins. */
    #[Override]
    public function log(string $message): void
    {
        error_log("[slack] {$message}");
    }
}
