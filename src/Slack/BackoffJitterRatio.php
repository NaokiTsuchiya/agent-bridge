<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * Qualifier for how much of the backoff delay may be taken off, as a fraction of it.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
#[Qualifier]
final class BackoffJitterRatio {}
