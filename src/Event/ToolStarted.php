<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Event;

final readonly class ToolStarted implements AgentEvent
{
    public function __construct(
        public string $name,
        public string $id,
    ) {}
}
