<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Event;

/**
 * An event emitted by an agent execution layer, in a shape no execution layer owns.
 *
 * The five implementations are {@see TextDelta}, {@see ToolStarted}, {@see ToolCompleted},
 * {@see TurnCompleted} and {@see AgentError}. Consumers dispatch on them exhaustively with
 * `match ($event::class)`, which raises `UnhandledMatchError` on an implementation the
 * consumer forgot — so adding a sixth implementation breaks consumers loudly rather than
 * silently dropping its events.
 *
 * `ToolCompleted` has no producer yet; see its own docblock.
 *
 * @api
 */
interface AgentEvent {}
