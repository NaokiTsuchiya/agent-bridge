<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Semantic;

use NaokiTsuchiya\AgentBridge\Thread\ThreadWorkspace;

/**
 * What a thread has been given: its id, its session and its directory.
 *
 * Registration only; a {@see ThreadWorkspace} that exists has already been checked, derived and
 * created. See {@see Platform} for why the class has to exist at all.
 *
 * @api
 */
final class Workspace {}
