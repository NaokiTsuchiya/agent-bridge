<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

/**
 * Where the client says what it did with a frame it is not passing on.
 *
 * Kept to one method because the only consumer is the recv loop, and because a test needs to see
 * that a branch was taken without a real log destination. Never give it the app token.
 *
 * @api
 */
interface SocketModeLogInterface
{
    /** Records one line. */
    public function log(string $message): void;
}
