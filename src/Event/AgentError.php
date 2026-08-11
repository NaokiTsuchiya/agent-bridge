<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Event;

/**
 * A turn that ends with nothing more to come, for a reason the caller has to be told.
 *
 * {@see ClaudeCliEventParser} still never emits one: a single line carries too little context
 * to say that a turn has failed, so an unreadable line answers with zero events. The judgement
 * belongs to whoever owns the process and sees the whole turn — today
 * {@see \NaokiTsuchiya\AgentBridge\Runner\PersistentCliRunner}, which emits this when a process
 * ends in the middle of a turn, or cannot be started at all.
 */
final readonly class AgentError implements AgentEvent
{
    /** @param string $message what to show the user in place of a reply */
    public function __construct(
        public string $message,
    ) {}
}
