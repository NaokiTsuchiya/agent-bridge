<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Worktree;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * Qualifier for the repository worktrees are cut from.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
#[Qualifier]
final class BaseRepository {}
