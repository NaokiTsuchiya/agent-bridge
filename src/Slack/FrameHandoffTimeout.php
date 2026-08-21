<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * Qualifier for how long a full channel may be waited on when a frame is handed on.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
#[Qualifier]
final class FrameHandoffTimeout {}
