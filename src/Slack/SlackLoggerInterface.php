<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

/**
 * Where this adapter says what it did with something it is not passing on: a frame it discarded, an
 * envelope it acknowledged twice, a reply it had nowhere to send.
 *
 * Kept to one method because a test needs to see that a branch was taken without a real log
 * destination. Never give it a token.
 *
 * @api
 */
interface SlackLoggerInterface
{
    /** Records one line. */
    public function log(string $message): void;
}
