<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Pipeline;

use NaokiTsuchiya\AgentBridge\Event\AgentEvent;

/**
 * A sixth implementation of {@see AgentEvent}, which the execution layer has no way to produce.
 *
 * {@see AgentEvent} promises that a consumer dispatching on the five implementations breaks loudly
 * rather than dropping a sixth one's events. Nothing can put that to the test from inside the five,
 * so the sixth exists here — and only here, so that adding one to `src/` still breaks loudly.
 *
 * @internal
 */
final class UnknownAgentEvent implements AgentEvent {}
