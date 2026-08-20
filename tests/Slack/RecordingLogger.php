<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Override;

/**
 * Collects the lines instead of writing them, so that a branch taken can be seen without output.
 *
 * @internal
 */
final class RecordingLogger implements SlackLoggerInterface
{
    /** @var list<string> every line, in order */
    public array $lines = [];

    /** Kept in order, so that a test can name the branch it expects to have been taken. */
    #[Override]
    public function log(string $message): void
    {
        $this->lines[] = $message;
    }
}
