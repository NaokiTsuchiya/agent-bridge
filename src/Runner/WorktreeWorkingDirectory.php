<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use NaokiTsuchiya\AgentBridge\Worktree\WorktreeException;
use NaokiTsuchiya\AgentBridge\Worktree\WorktreeManager;
use Override;

/**
 * Sends the runner to the worktree the thread's own branch is checked out in.
 *
 * @api
 */
final class WorktreeWorkingDirectory implements WorkingDirectoryResolver
{
    /** @param WorktreeManager $worktrees creates or recovers the directory when it is not there */
    public function __construct(
        private WorktreeManager $worktrees,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws WorktreeException When the worktree cannot be created or lands outside the repository.
     */
    #[Override]
    public function resolve(ThreadId $thread): string
    {
        return $this->worktrees->worktreeFor($thread);
    }
}
