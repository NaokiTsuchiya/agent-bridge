<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Event;

final readonly class TextDelta implements AgentEvent
{
    /** @param string $text a fragment to append to what came before, not a whole message */
    public function __construct(
        public string $text,
    ) {}
}
