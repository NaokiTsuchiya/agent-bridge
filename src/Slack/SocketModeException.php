<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use RuntimeException;

/**
 * Everything that can go wrong between the app token and a usable Socket Mode connection.
 *
 * The recv loop treats this as "lose the connection and reconnect", so nothing that carries it may
 * put the app token in its message.
 *
 * @api
 */
final class SocketModeException extends RuntimeException {}
