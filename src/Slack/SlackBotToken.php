<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use InvalidArgumentException;
use SensitiveParameter;

use function str_starts_with;
use function trim;

/**
 * A bot token, which exists only if it could be one.
 *
 * The counterpart of {@see SlackAppToken}: that one opens the Socket Mode connection, this one makes
 * the Web API calls, and they are different kinds of credential that cannot stand in for each other.
 * Keeping them as separate types is what makes swapping them a compile-time mistake rather than an
 * `invalid_auth` in the middle of somebody's turn. Nothing here ever puts the value in an exception
 * message, because those are read in logs.
 *
 * @api
 */
final class SlackBotToken
{
    /** Every bot token starts with this; an app-level token (`xapp-`) is rejected before any call. */
    public const string PREFIX = 'xoxb-';

    /** @throws InvalidArgumentException when the value cannot be a bot token */
    public function __construct(
        #[SensitiveParameter]
        public string $value,
    ) {
        if (trim($value) === '') {
            throw new InvalidArgumentException('A bot token cannot be blank.');
        }

        if (!str_starts_with($value, self::PREFIX)) {
            throw new InvalidArgumentException(
                'A bot token starts with "'
                . self::PREFIX
                . '". An app-level token ("'
                . SlackAppToken::PREFIX
                . '") opens a Socket Mode connection but will not make a Web API call.',
            );
        }
    }
}
