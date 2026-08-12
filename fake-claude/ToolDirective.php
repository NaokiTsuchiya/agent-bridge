<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\FakeClaude;

/** The tool call a scenario asks a turn to make, and the result to hand back for it. */
final readonly class ToolDirective
{
    /** @param string $id ties the tool_use line to the tool_result line that answers it */
    public function __construct(
        public string $name,
        public string $id,
        public string $result,
    ) {}
}
