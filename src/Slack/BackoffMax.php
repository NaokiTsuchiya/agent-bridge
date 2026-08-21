<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * Qualifier for the ceiling the backoff's doubling stops at.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
#[Qualifier]
final class BackoffMax {}
