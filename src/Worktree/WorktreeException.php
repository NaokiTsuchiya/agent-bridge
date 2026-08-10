<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Worktree;

use RuntimeException;

/** A worktree could not be produced and nothing is left to recover from. */
final class WorktreeException extends RuntimeException {}
