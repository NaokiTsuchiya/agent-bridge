<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Event;

/**
 * No producer emits this yet, on purpose.
 *
 * {@see ClaudeCliEventParser} answers every unreadable line with zero events rather than an
 * error event, because a single line carries too little context to say a turn has failed.
 * Deciding which failures deserve an error event belongs to the execution layer that owns
 * the process, and is issue #5's job. Until then a consumer's `match` arm for this class is
 * unreachable — that is expected, not a defect.
 */
final readonly class AgentError implements AgentEvent
{
    public function __construct(
        public string $message,
    ) {}
}
