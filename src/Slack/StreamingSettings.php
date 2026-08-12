<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

/**
 * How often a streamed reply may be sent, and how much may go in one send.
 *
 * Every value is here rather than in the code that acts on it, so that a deployment can move them
 * and a test can shrink them to something it can drive. The defaults are Slack's own limits, read
 * from docs.slack.dev in 2026-08 and recorded in `docs/poc-design.md` 4.5.
 *
 * @api
 */
final readonly class StreamingSettings
{
    /**
     * @param int   $throttleMilliseconds  how long to collect fragments for before sending them as
     *                                     one. `chat.appendStream` is a Tier 4 method (100+ calls a
     *                                     minute), and 600ms is 100 a minute: anything shorter runs
     *                                     the turn into the limit rather than answering faster
     * @param int   $maxTextCharacters     how much reply text may go in one call; Slack's limit for
     *                                     `markdown_text`. More than this is split over several
     * @param int   $maxChunkCharacters    how long a task update announcement may be; Slack's limit
     *                                     for a `task_update` chunk
     * @param int   $maxRateLimitRetries   how many times a rate limited call is made again before
     *                                     it is given up on. A limit rather than none, so that a
     *                                     workspace answering 429 forever cannot hold a turn open
     * @param float $maxRetryAfterSeconds  the longest wait honoured, whatever Slack asks for. A
     *                                     `Retry-After` of an hour would otherwise park the turn
     */
    public function __construct(
        public int $throttleMilliseconds = 600,
        public int $maxTextCharacters = 12_000,
        public int $maxChunkCharacters = 256,
        public int $maxRateLimitRetries = 3,
        public float $maxRetryAfterSeconds = 60.0,
    ) {}
}
