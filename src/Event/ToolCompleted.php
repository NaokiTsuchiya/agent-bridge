<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Event;

/**
 * No producer emits this yet, on purpose.
 *
 * The wire form that carries a tool result was not yet measured when the type was defined,
 * so {@see ClaudeCliEventParser} never returns one; mapping it is issue #5's job, together
 * with the fake CLI that has to reproduce the same wire form. Until then a consumer's
 * `match` arm for this class is unreachable — that is expected, not a defect.
 */
final readonly class ToolCompleted implements AgentEvent
{
    public function __construct(
        public string $id,
        public bool $success,
    ) {}
}
