<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * Qualifier for the turn timeout in seconds.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
#[Qualifier]
final class TurnSeconds {}
