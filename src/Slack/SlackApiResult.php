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
     * @param bool   $ok         whether Slack carried the call out
     * @param string $error      what it said went wrong, empty when nothing did
     * @param string $ts         the message the call created, empty when it created none. A stream
     *                           is appended to and stopped by this value, so it is the one part of
     *                           a body a caller here has to see
     * @param float  $retryAfter how long Slack asked to be left alone for, in seconds; 0.0 when it
     *                           asked for nothing, which is every answer but a rate limited one
     */
    public function __construct(
        public bool $ok,
        public string $error = '',
        public string $ts = '',
        public float $retryAfter = 0.0,
    ) {}
}
