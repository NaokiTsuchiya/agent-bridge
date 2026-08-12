<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use InvalidArgumentException;
use Override;
use Ray\Di\ProviderInterface;

use function getenv;

/**
 * Where the Web API client comes from, bot token and all.
 *
 * A provider rather than a bound instance because the token is the running machine's, not the build
 * machine's: `Ray\Compiler` freezes whatever `toInstance()` is given into a script that ships with
 * the image, and a credential must never be in one.
 *
 * @implements ProviderInterface<SlackApiClient>
 *
 * @api
 */
final class SlackApiClientProvider implements ProviderInterface
{
    /** Where the bot token is read from; the name Slack's own documentation uses. */
    public const string ENVIRONMENT_VARIABLE = 'SLACK_BOT_TOKEN';

    /**
     * @param HttpClientFactoryInterface $clients where the coroutine HTTP clients come from
     * @param SleeperInterface           $sleeper what a rate limited call is waited out with
     */
    public function __construct(
        private HttpClientFactoryInterface $clients,
        private SleeperInterface $sleeper,
        private StreamingSettings $settings,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws SlackException when the variable is unset or does not hold a bot token
     */
    #[Override]
    public function get(): SlackApiClient
    {
        $value = getenv(self::ENVIRONMENT_VARIABLE);

        if ($value === false) {
            throw new SlackException(
                self::ENVIRONMENT_VARIABLE
                . ' is not set. Install the app into the workspace and export its bot token; '
                . 'see docs/slack-adapter.md.',
            );
        }

        try {
            $token = new SlackBotToken($value);
        } catch (InvalidArgumentException $exception) {
            // The value is never repeated, here or in what is caught.
            throw new SlackException(
                self::ENVIRONMENT_VARIABLE . ' does not hold a usable token. ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        // Wrapped rather than built into the transport: waiting is a decision about how to answer
        // Slack, while the transport is about reaching it, and only the wrapper can be exercised
        // without a workspace.
        return new RetryingSlackApiClient(
            new SwooleSlackApiClient($token, $this->clients),
            $this->sleeper,
            $this->settings,
        );
    }
}
