<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use InvalidArgumentException;

use function getenv;

/**
 * Where an app-level token comes from.
 *
 * Kept apart from {@see SlackAppToken} so that the value object stays about what a token is, and
 * this stays about the one place this app reads one from. A malformed value is reported as a Socket
 * Mode failure rather than an argument error: from a caller's side, the environment is what is
 * wrong, not the call it just made.
 *
 * @api
 */
final class SlackAppTokenFactory
{
    /** Where the token is read from; the name Slack's own documentation uses. */
    public const string ENVIRONMENT_VARIABLE = 'SLACK_APP_TOKEN';

    /** @throws SocketModeException when the variable is unset or does not hold an app-level token */
    public static function fromEnvironment(): SlackAppToken
    {
        $value = getenv(self::ENVIRONMENT_VARIABLE);

        if ($value === false) {
            throw new SocketModeException(
                self::ENVIRONMENT_VARIABLE
                . ' is not set. Create an app-level token with the '
                . '"connections:write" scope and export it; see docs/slack-socket-mode.md.',
            );
        }

        try {
            return new SlackAppToken($value);
        } catch (InvalidArgumentException $exception) {
            // The value is never repeated, here or in what is caught.
            throw new SocketModeException(
                self::ENVIRONMENT_VARIABLE . ' does not hold a usable token. ' . $exception->getMessage(),
                previous: $exception,
            );
        }
    }
}
