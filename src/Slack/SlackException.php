<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use RuntimeException;

/**
 * The Slack front end cannot be brought up.
 *
 * Separate from {@see SocketModeException}, which the recv loop answers by reconnecting: nothing
 * here is worth retrying, because what is missing is a setting rather than a connection. Nothing
 * that carries it may put a token in its message.
 *
 * @api
 */
final class SlackException extends RuntimeException {}
