<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Semantic;

use NaokiTsuchiya\AgentBridge\Thread\ThreadId;

/**
 * What a chat platform calls one of its threads.
 *
 * Registration only; the rules live in {@see ThreadId}. See {@see Platform} for why the class has
 * to exist at all.
 *
 * @api
 */
final class NativeId {}
