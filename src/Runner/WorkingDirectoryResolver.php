<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use NaokiTsuchiya\AgentBridge\Thread\ThreadId;

/**
 * Where a thread's work happens.
 *
 * Kept apart from {@see AgentRunner} on purpose: a runner has to know the directory to start a
 * process in, but a caller must not have to, or every caller would need to know how this project
 * lays out worktrees.
 *
 * @api
 */
interface WorkingDirectoryResolver
{
    /** @return string an absolute path that exists, which the thread's process is started in */
    public function resolve(ThreadId $thread): string;
}
