<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Override;

use function min;

/**
 * The Web API with Slack's own rate limit answered rather than reported.
 *
 * A 429 is not a failure of the call, it is Slack asking for a moment — and a streamed reply that
 * treated it as a failure would lose the fragment and, worse, could not tell a limit from a refusal
 * when deciding whether to fall back. So the wait is taken here, in front of every call, and what
 * reaches the caller is the answer to the retry.
 *
 * The wait is the one Slack asks for, capped, and there are only so many of them: a workspace that
 * answered 429 forever would otherwise hold a turn open for as long as it liked.
 *
 * @api
 */
final class RetryingSlackApiClient implements SlackApiClient
{
    /**
     * @param SlackApiClient   $api     what actually reaches the workspace
     * @param SleeperInterface $sleeper what gives up the time; a test records it instead
     */
    public function __construct(
        private SlackApiClient $api,
        private SleeperInterface $sleeper,
        private StreamingSettings $settings,
    ) {}

    /** {@inheritDoc} */
    #[Override]
    public function call(string $method, array $arguments): SlackApiResult
    {
        $result = $this->api->call($method, $arguments);

        // `retryAfter` is above zero on a rate limited answer and on no other, so this asks "was
        // this a limit" without the result having to carry a second flag saying the same thing.
        for ($retry = 0; $retry < $this->settings->maxRateLimitRetries && $result->retryAfter > 0.0; $retry++) {
            $this->sleeper->sleep(min($result->retryAfter, $this->settings->maxRetryAfterSeconds));
            $result = $this->api->call($method, $arguments);
        }

        return $result;
    }
}
