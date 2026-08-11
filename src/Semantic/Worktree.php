<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Semantic;

use NaokiTsuchiya\AgentBridge\Worktree\WorktreeManager;

/**
 * The directory a thread's work happens in.
 *
 * Registration only; the path is produced by {@see WorktreeManager} and exists by the time it is
 * carried here. See {@see Platform} for why the class has to exist at all.
 *
 * @api
 */
final class Worktree {}
