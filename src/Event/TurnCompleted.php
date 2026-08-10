<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Event;

final readonly class TurnCompleted implements AgentEvent
{
    /** @param string $sessionId what the next turn resumes; empty when the line carried none */
    public function __construct(
        public bool $success,
        public string $sessionId,
    ) {}
}
