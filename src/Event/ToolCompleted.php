<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Event;

/**
 * Produced by {@see ClaudeCliEventParser} from a `user` line whose content carries a tool_result.
 *
 * `is_error: true` maps to `success: false`; an outcome that cannot be read (the key missing or
 * not a boolean) also maps to `success: false` — the same rule {@see ClaudeCliEventParser} applies
 * to a turn's own outcome, so that an unreadable result is never upgraded to one that went well.
 */
final readonly class ToolCompleted implements AgentEvent
{
    /** @param string $id the identifier of the {@see ToolStarted} this completes */
    public function __construct(
        public string $id,
        public bool $success,
    ) {}
}
