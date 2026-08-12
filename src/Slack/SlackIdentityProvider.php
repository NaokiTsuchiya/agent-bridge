<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use InvalidArgumentException;
use Override;
use Ray\Di\ProviderInterface;

use function getenv;

/**
 * Where this app's own user id comes from.
 *
 * Read from the environment rather than from the events themselves. Slack does put the installing
 * app's user id in an `event_callback`'s `authorizations`, but a judgement about **whose** posts to
 * ignore must not quietly stop working because a field moved: a configured value either is there at
 * boot or the process does not start.
 *
 * @implements ProviderInterface<SlackIdentity>
 *
 * @api
 */
final class SlackIdentityProvider implements ProviderInterface
{
    /** Where the bot user id is read from; shown as "Bot User ID" in the app's settings. */
    public const string ENVIRONMENT_VARIABLE = 'SLACK_BOT_USER_ID';

    /**
     * {@inheritDoc}
     *
     * @throws SlackException when the variable is unset or cannot name a user
     */
    #[Override]
    public function get(): SlackIdentity
    {
        $value = getenv(self::ENVIRONMENT_VARIABLE);

        if ($value === false) {
            throw new SlackException(
                self::ENVIRONMENT_VARIABLE
                . ' is not set. Without it this app would answer its own posts; '
                . 'see docs/slack-adapter.md.',
            );
        }

        try {
            return new SlackIdentity($value);
        } catch (InvalidArgumentException $exception) {
            throw new SlackException(
                self::ENVIRONMENT_VARIABLE . ' does not name a user. ' . $exception->getMessage(),
                previous: $exception,
            );
        }
    }
}
