<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Event;

final readonly class TurnCompleted implements AgentEvent
{
    public function __construct(
        public bool $success,
        public string $sessionId,
    ) {}
}
