<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

/**
 * How one Web API call went.
 *
 * A value rather than an exception because every caller here answers a failure the same way — show
 * something else, or say nothing — and because a turn in progress must not be ended by a post that
 * did not go through.
 *
 * @api
 */
final readonly class SlackApiResult
{
    /**
     * @param bool   $ok    whether Slack carried the call out
     * @param string $error what it said went wrong, empty when nothing did
     */
    public function __construct(
        public bool $ok,
        public string $error = '',
    ) {}
}
