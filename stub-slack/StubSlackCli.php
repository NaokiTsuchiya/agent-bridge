<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\StubSlack;

use Swoole\Exception;

use function fflush;
use function fwrite;
use function json_encode;
use function Swoole\Coroutine\run;

use const STDERR;
use const STDOUT;

/**
 * The entrypoint `stub-slack/bin/stub-slack` hands its argv to: one canned Socket Mode scenario,
 * spoken over a real TLS socket on the port it is given.
 *
 * `apps.connections.open`/upgrade/frame scripts are `StubSlackServer`'s job; this class only wires
 * that up to a process — argv in, observation lines and ack lines on stdout out — the way
 * `NaokiTsuchiya\AgentBridge\FakeClaude\FakeClaudeCli::main()` wires the fake CLI up to its argv.
 */
final class StubSlackCli
{
    /** What the WebSocket handshake is answered with; only its `type` is read by production code. */
    private const string HELLO_FRAME = '{"type":"hello","num_connections":1}';

    /** The envelope a caller's integration test acks; fixed, because this stub has one scenario. */
    private const string ENVELOPE_ID = 'stub-envelope-1';

    /** The default scenario: one event, one ack. */
    private const string MODE_ACK = 'ack';

    /** The keepalive scenario: ping first, then an event on the same socket. */
    private const string MODE_PING = 'ping';

    /** The reconnect scenario: close once, then accept a fresh connection. */
    private const string MODE_RECONNECT = 'reconnect';

    /**
     * @param list<string> $argv   `$argv[1]` is the port to bind, chosen by the caller (`FreePort`)
     *                              before this process is started; `$argv[2]` optionally selects one
     *                              of `ack`, `ping`, or `reconnect`
     * @param resource     $stderr where the usage message goes when `$argv` has no port; a test
     *                              passes something other than the real `STDERR` to read it back
     *
     * @return int `1` when no port was given (nothing is bound, nothing runs); otherwise this call
     *             does not return until the process is killed, and its return value is unreachable
     *
     * @throws StubSlackException when the scenario, events frame, or certificate cannot be built
     */
    public static function main(array $argv, mixed $stderr = STDERR): int
    {
        $port = $argv[1] ?? null;
        $mode = $argv[2] ?? self::MODE_ACK;

        if ($port === null) {
            fwrite($stderr, data: "usage: stub-slack <port> [ack|ping|reconnect]\n");

            return 1;
        }

        $eventsFrame = json_encode([
            'type' => 'events_api',
            'envelope_id' => self::ENVELOPE_ID,
            'payload' => ['event' => ['type' => 'app_mention', 'text' => 'ping']],
        ]);

        if ($eventsFrame === false) {
            // One string and one fixed literal under known keys cannot fail to encode.
            throw new StubSlackException('Cannot build the canned events_api frame.');
        }

        run(
            /** @throws StubSlackException|Exception when the certificate cannot be generated or the listener cannot be bound */
            static function () use ($port, $mode, $eventsFrame): void {
                $server = new StubSlackServer(
                    '127.0.0.1',
                    (int) $port,
                    SelfSignedCertificate::generate(),
                    self::scenario($mode, $eventsFrame),
                    static function (string $ack): void {
                        self::emit("ACK {$ack}");
                    },
                    new StubSlackApi(),
                    self::emit(...),
                );

                self::emit('READY');

                $server->start();
            },
        );

        return 0;
    }

    /** Writes one observation line to stdout, flushed, so the parent process sees it immediately. */
    private static function emit(string $line): void
    {
        fwrite(STDOUT, data: "{$line}\n");
        fflush(STDOUT);
    }

    /** Builds the canned connection script the requested integration mode needs. */
    private static function scenario(string $mode, string $eventsFrame): StubSlackScenario
    {
        return match ($mode) {
            self::MODE_ACK => new StubSlackScenario(self::HELLO_FRAME, $eventsFrame),
            self::MODE_PING => new StubSlackScenario(self::HELLO_FRAME, $eventsFrame, [[
                StubSlackScenario::SEND_HELLO,
                StubSlackScenario::SEND_PING,
                StubSlackScenario::SEND_EVENT,
                StubSlackScenario::EXPECT_PONG,
                StubSlackScenario::EXPECT_ACK,
            ]]),
            self::MODE_RECONNECT => new StubSlackScenario(self::HELLO_FRAME, $eventsFrame, [
                [StubSlackScenario::SEND_HELLO, StubSlackScenario::CLOSE],
                [StubSlackScenario::SEND_HELLO, StubSlackScenario::SEND_EVENT, StubSlackScenario::EXPECT_ACK],
            ]),
            default => throw new StubSlackException("unknown stub-slack scenario: {$mode}"),
        };
    }
}
