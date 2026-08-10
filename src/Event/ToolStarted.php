<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Event;

final readonly class ToolStarted implements AgentEvent
{
    /** @param string $id what the {@see ToolCompleted} for this call refers back to */
    public function __construct(
        public string $name,
        public string $id,
    ) {}
}
