<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Pipeline;

use NaokiTsuchiya\AgentBridge\Thread\ThreadWorkspace;

/**
 * A turn that happened, whichever way it went.
 *
 * The chain ends at {@see CompletedTurn} or at {@see FailedTurn}, and which one it is is the
 * answer to "did the agent stand behind this turn". A caller that does not ask that question —
 * one that only has to let the thread's child go, or to show what the reader was told — takes
 * either through this.
 *
 * @api
 */
interface Turn
{
    /** The thread the turn belonged to, with the session it continued and the directory it ran in. */
    public ThreadWorkspace $workspace { get; }

    /** What the reader was told, without the tool announcements. */
    public string $reply { get; }
}
