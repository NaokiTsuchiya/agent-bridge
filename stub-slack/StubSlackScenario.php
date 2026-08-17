<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\StubSlack;

/**
 * The two frames a `StubSlackServer` sends over every WebSocket connection it accepts.
 *
 * Kept apart from `StubSlackServer`'s other constructor arguments (host, port, certificate) purely
 * to keep that constructor's parameter list short — this is a plain value, not a concept with
 * behaviour of its own.
 */
final readonly class StubSlackScenario
{
    /**
     * @param string $helloFrame  sent verbatim as the first WebSocket frame, as Slack sends `hello`
     * @param string $eventsFrame sent verbatim as the second frame, carrying an `envelope_id`
     */
    public function __construct(
        public string $helloFrame,
        public string $eventsFrame,
    ) {}
}
