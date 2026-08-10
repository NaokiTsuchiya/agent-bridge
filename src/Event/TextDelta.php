<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Event;

final readonly class TextDelta implements AgentEvent
{
    public function __construct(
        public string $text,
    ) {}
}
