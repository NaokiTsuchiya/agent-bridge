<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use InvalidArgumentException;

use function trim;

/**
 * Which user this app is, seen from inside the workspace.
 *
 * A bot hears its own posts back through the same event stream it hears everybody else through, so
 * without this the first reply would be answered as if a person had written it, and that answer
 * would be heard again. It is a value of its own rather than a string parameter so that the one
 * thing it is for cannot be filled in with a channel or a token by accident.
 *
 * @api
 */
final class SlackIdentity
{
    /** @throws InvalidArgumentException when the value cannot name a user */
    public function __construct(
        public string $botUserId,
    ) {
        if (trim($botUserId) === '') {
            throw new InvalidArgumentException('A bot user id cannot be blank.');
        }
    }
}
