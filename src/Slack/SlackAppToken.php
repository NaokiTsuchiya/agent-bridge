<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use SensitiveParameter;

use function getenv;
use function str_starts_with;
use function trim;

/**
 * The app-level token that `apps.connections.open` is called with.
 *
 * It has no accessor for its value and no `__toString`: the only way out is the header, so a stray
 * `var_dump` or log line cannot leak it. Nothing here ever puts the value in an exception message.
 *
 * @api
 */
final class SlackAppToken
{
    /** Where the token is read from; the name Slack's own documentation uses. */
    public const string ENVIRONMENT_VARIABLE = 'SLACK_APP_TOKEN';

    /** Every app-level token starts with this; a bot token (`xoxb-`) is rejected before any call. */
    private const string PREFIX = 'xapp-';

    /** @throws SocketModeException when the value cannot be an app-level token */
    public function __construct(
        #[SensitiveParameter]
        private string $value,
    ) {
        if (trim($value) === '') {
            throw new SocketModeException(self::ENVIRONMENT_VARIABLE
            . ' is empty. Put the app-level token of your Slack app in it.');
        }

        if (!str_starts_with($value, self::PREFIX)) {
            throw new SocketModeException(
                self::ENVIRONMENT_VARIABLE
                . ' must hold an app-level token, which starts with "'
                . self::PREFIX
                . '". A bot token ("xoxb-") or a workspace token will not open a Socket Mode connection.',
            );
        }
    }

    /** @throws SocketModeException when the variable is unset or does not hold an app-level token */
    public static function fromEnvironment(): self
    {
        $value = getenv(self::ENVIRONMENT_VARIABLE);

        if ($value === false) {
            throw new SocketModeException(
                self::ENVIRONMENT_VARIABLE
                . ' is not set. Create an app-level token with the '
                . '"connections:write" scope and export it; see docs/slack-socket-mode.md.',
            );
        }

        return new self($value);
    }

    /** The `Authorization` header value, which is the only place the token is allowed to appear. */
    public function authorizationHeader(): string
    {
        return "Bearer {$this->value}";
    }
}
