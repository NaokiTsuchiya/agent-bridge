<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use InvalidArgumentException;
use SensitiveParameter;

use function str_starts_with;
use function trim;

/**
 * An app-level token, which exists only if it could be one.
 *
 * It knows nothing about where tokens come from ({@see SlackAppTokenFactory}) or what they are put
 * into ({@see SwooleSocketModeConnector}) — only what makes a value one. Nothing here ever puts the
 * value in an exception message, because those are read in logs.
 *
 * @api
 */
final class SlackAppToken
{
    /** Every app-level token starts with this; a bot token (`xoxb-`) is rejected before any call. */
    public const string PREFIX = 'xapp-';

    /** @throws InvalidArgumentException when the value cannot be an app-level token */
    public function __construct(
        #[SensitiveParameter]
        public string $value,
    ) {
        if (trim($value) === '') {
            throw new InvalidArgumentException('An app-level token cannot be blank.');
        }

        if (!str_starts_with($value, self::PREFIX)) {
            throw new InvalidArgumentException(
                'An app-level token starts with "'
                . self::PREFIX
                . '". A bot token ("xoxb-") or a workspace token will not open a Socket Mode connection.',
            );
        }
    }
}
