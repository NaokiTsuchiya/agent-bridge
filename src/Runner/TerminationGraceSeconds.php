<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * Qualifier for how long a terminated process is given to disappear.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
#[Qualifier]
final class TerminationGraceSeconds {}
