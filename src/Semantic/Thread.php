<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Semantic;

use NaokiTsuchiya\AgentBridge\Thread\ThreadId;

/**
 * The conversation a turn belongs to.
 *
 * Registration only; a {@see ThreadId} that exists has already been checked. See {@see Platform}
 * for why the class has to exist at all.
 *
 * @api
 */
final class Thread {}
