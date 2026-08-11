<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Thread;

/**
 * Everything a thread has been given: which thread it is, which session it continues, and where
 * its work happens.
 *
 * The three travel together because they are one answer to one question — what this application
 * derives from a thread id, given that it stores nothing. Whoever holds one of these holds all
 * three, and cannot be handed a session that belongs to another thread's worktree.
 *
 * @api
 */
final readonly class ThreadWorkspace
{
    /**
     * @param ThreadId $thread    the thread, already checked
     * @param string   $sessionId the Claude Code session the thread resumes into
     * @param string   $worktree  the absolute path the turn runs in, which exists
     */
    public function __construct(
        public ThreadId $thread,
        public string $sessionId,
        public string $worktree,
    ) {}
}
