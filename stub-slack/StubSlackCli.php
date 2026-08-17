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
 * `apps.connections.open`/upgrade/hello/events_api are `StubSlackServer`'s job; this class only
 * wires that up to a process — argv in, an ack line on stdout out — the way
 * `NaokiTsuchiya\AgentBridge\FakeClaude\FakeClaudeCli::main()` wires the fake CLI up to its argv.
 */
final class StubSlackCli
{
    /** What the WebSocket handshake is answered with; only its `type` is read by production code. */
    private const string HELLO_FRAME = '{"type":"hello","num_connections":1}';

    /** The envelope a caller's integration test acks; fixed, because this stub has one scenario. */
    private const string ENVELOPE_ID = 'stub-envelope-1';

    /**
     * @param list<string> $argv   `$argv[1]` is the port to bind, chosen by the caller (`FreePort`)
     *                              before this process is started
     * @param resource     $stderr where the usage message goes when `$argv` has no port; a test
     *                              passes something other than the real `STDERR` to read it back
     *
     * @return int `1` when no port was given (nothing is bound, nothing runs); otherwise this call
     *             does not return until the process is killed, and its return value is unreachable
     *
     * @throws StubSlackException when the events frame cannot be built or the certificate cannot be generated
     */
    public static function main(array $argv, mixed $stderr = STDERR): int
    {
        $port = $argv[1] ?? null;

        if ($port === null) {
            fwrite($stderr, data: "usage: stub-slack <port>\n");

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
            static function () use ($port, $eventsFrame): void {
                $scenario = new StubSlackScenario(self::HELLO_FRAME, $eventsFrame);
                $server = new StubSlackServer(
                    '127.0.0.1',
                    (int) $port,
                    SelfSignedCertificate::generate(),
                    $scenario,
                    static function (string $ack): void {
                        // The only channel a separate process has back to the test that spawned it.
                        fwrite(STDOUT, data: "ACK {$ack}\n");
                        fflush(STDOUT);
                    },
                );

                fwrite(STDOUT, data: "READY\n");
                fflush(STDOUT);

                $server->start();
            },
        );

        return 0;
    }
}
