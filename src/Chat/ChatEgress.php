<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Chat;

use NaokiTsuchiya\AgentBridge\Thread\ThreadId;

/**
 * Where an answer goes out to, whichever front end the thread lives on.
 *
 * @api
 */
interface ChatEgress
{
    /** @return StreamHandle the reply of this thread, ready to be written to */
    public function open(ThreadId $thread): StreamHandle;

    /**
     * Shows what is going on while there is no reply text yet.
     *
     * Separate from the reply because a front end that has somewhere to put it — a status line, a
     * temporary message — should not have to fold it into the answer itself.
     */
    public function status(ThreadId $thread, string $text): void;
}
