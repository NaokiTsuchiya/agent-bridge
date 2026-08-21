<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * Qualifier for how many envelope ids are remembered at once.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
#[Qualifier]
final class EnvelopeCapacity {}
