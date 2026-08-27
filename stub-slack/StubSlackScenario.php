<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\StubSlack;

/**
 * The scripted frames and expectations a `StubSlackServer` runs per WebSocket connection it accepts.
 *
 * Kept apart from `StubSlackServer`'s other constructor arguments (host, port, certificate) purely
 * to keep that constructor's parameter list short — this is a plain value, not a concept with
 * behaviour of its own.
 */
final readonly class StubSlackScenario
{
    /** Send the canned `hello` frame for this connection. */
    public const string SEND_HELLO = 'send_hello';

    /** Send the canned `events_api` frame for this connection. */
    public const string SEND_EVENT = 'send_event';

    /** Send a WebSocket ping frame and expect the peer to answer it. */
    public const string SEND_PING = 'send_ping';

    /** Read the next text frame as the event acknowledgement. */
    public const string EXPECT_ACK = 'expect_ack';

    /** Read the next pong frame as the keepalive answer. */
    public const string EXPECT_PONG = 'expect_pong';

    /** Send a WebSocket close frame and end this connection script. */
    public const string CLOSE = 'close';

    /**
     * @param list<list<string>> $connections one ordered step list per accepted WebSocket connection;
     *                                        once these run out, the last one is reused
     */
    public function __construct(
        public string $helloFrame,
        public string $eventsFrame,
        public array $connections = [[self::SEND_HELLO, self::SEND_EVENT, self::EXPECT_ACK]],
    ) {}

    /** @return list<string> */
    public function stepsFor(int $connection): array
    {
        $requested = $connection - 1;
        $steps = $this->connections[$requested] ?? null;

        if ($steps !== null) {
            return $steps;
        }

        $fallback = [];
        foreach ($this->connections as $script) {
            $fallback = $script;
        }

        return $fallback;
    }
}
