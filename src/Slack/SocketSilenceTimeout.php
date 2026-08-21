<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * Qualifier for how long a Socket Mode connection may produce nothing before it is discarded.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
#[Qualifier]
final class SocketSilenceTimeout {}
