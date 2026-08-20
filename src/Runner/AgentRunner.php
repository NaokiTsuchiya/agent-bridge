<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use NaokiTsuchiya\AgentBridge\Event\AgentEvent;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;

/**
 * An agent an application talks to, one thread at a time.
 *
 * A caller hands over a {@see ThreadId} and a prompt, and gets the agent's answer back as
 * {@see AgentEvent}s. Everything else — where the work happens, how a thread's history is
 * identified, whether a process is kept alive between turns — belongs to the implementation
 * and is deliberately absent from this declaration, so that a second implementation with
 * other answers to those questions can be dropped in without touching a caller.
 *
 * @api
 */
interface AgentRunner
{
    /**
     * Answers one prompt on one thread, picking up whatever that thread said before.
     *
     * @param ThreadId $thread which conversation the prompt belongs to
     * @param string   $prompt what to answer
     *
     * @return iterable<AgentEvent> the answer as it is produced, ending with the event that
     *                              marks the turn finished
     */
    public function send(ThreadId $thread, string $prompt): iterable;

    /**
     * Gives up whatever the thread was holding on to.
     *
     * A later {@see send()} on the same thread is allowed and keeps the thread's history: what
     * is released here is machinery, not the conversation.
     */
    public function close(ThreadId $thread): void;

    /**
     * Answers how many child processes are being held right now.
     *
     * An execution layer that keeps no processes between turns answers 0.
     *
     * @return int at most the configured limit
     */
    public function liveProcesses(): int;
}
