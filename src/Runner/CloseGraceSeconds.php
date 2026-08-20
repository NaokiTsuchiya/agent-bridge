<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * Qualifier for how long a closing process is given to exit on its own.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
#[Qualifier]
final class CloseGraceSeconds {}
