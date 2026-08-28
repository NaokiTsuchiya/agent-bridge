<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Integration;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Slack\Backoff;
use NaokiTsuchiya\AgentBridge\Slack\CoroutineSleeper;
use NaokiTsuchiya\AgentBridge\Slack\EnvelopeLog;
use NaokiTsuchiya\AgentBridge\Slack\FrameRouter;
use NaokiTsuchiya\AgentBridge\Slack\MtRandomSource;
use NaokiTsuchiya\AgentBridge\Slack\ReconnectDelay;
use NaokiTsuchiya\AgentBridge\Slack\RecordingLogger;
use NaokiTsuchiya\AgentBridge\Slack\SlackAppToken;
use NaokiTsuchiya\AgentBridge\Slack\SocketModeClient;
use NaokiTsuchiya\AgentBridge\Slack\SwooleHttpClientFactory;
use NaokiTsuchiya\AgentBridge\Slack\SwooleSocketModeConnector;
use NaokiTsuchiya\AgentBridge\StubSlack\FreePort;
use NaokiTsuchiya\AgentBridge\StubSlack\StubSlackException;
use NaokiTsuchiya\AgentBridge\Support\ChildProcesses;
use NaokiTsuchiya\AgentBridge\Support\CliProcess;
use NaokiTsuchiya\AgentBridge\Support\Coro;
use NaokiTsuchiya\AgentBridge\Support\Json;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

use function dirname;
use function microtime;
use function str_starts_with;
use function strlen;
use function substr;
use function usleep;

use const PHP_BINARY;

/**
 * Socket Mode's receive path, over a real TLS socket to a real separate process — the one thing
 * `tests/Slack/` cannot show, and what `docs/slack-socket-mode.md` still leaves to manual checks is
 * now only the real-workspace, long-running half of steps 4 to 6 there.
 *
 * Every production class between the app token and the delivered payload runs unmodified: only
 * `SwooleSocketModeConnector`'s `apiHost`/`apiPort` point at the stub instead of `slack.com`.
 */
#[Group('integration')]
final class SocketModeStubTest extends TestCase
{
    /** How long the child is given to print its readiness line. */
    private const float READY_TIMEOUT = 5.0;

    /** How long a stub observation line is given to appear once the client should have caused it. */
    private const float LINE_TIMEOUT = 2.0;

    /** Short enough that {@see SocketModeClient::stop()} ends the run deterministically and fast. */
    private const float SILENCE_TIMEOUT = 0.5;

    /** The baseline stub script: hello, one event, one ack. */
    private const string MODE_ACK = 'ack';

    /** The keepalive script: ping and event on one live connection. */
    private const string MODE_PING = 'ping';

    /** The reconnect script: one forced close before the successful connection. */
    private const string MODE_RECONNECT = 'reconnect';

    /** @var array<string, mixed> The one payload every stub mode eventually delivers. */
    private const array EXPECTED_PAYLOAD = ['event' => ['type' => 'app_mention', 'text' => 'ping']];

    /** The stub-slack child process this case started, so `tearDown()` always ends it. */
    private ?CliProcess $process = null;

    /** {@inheritDoc} */
    #[Override]
    protected function tearDown(): void
    {
        $this->process?->stop();
    }

    /**
     * Connects, reads `hello`, reads the `events_api` payload, and confirms — from the stub's own
     * side — that the ack for it arrived. No child process or zombie survives the test.
     *
     * @throws Throwable
     * @throws StubSlackException
     */
    #[Test]
    public function receivesAndAcknowledgesAnEventOverARealTlsSocket(): void
    {
        $port = FreePort::acquire();
        $process = $this->startStub($port);
        $logger = new RecordingLogger();
        /** @var array<string, mixed>|false|null $payload */
        $payload = null;

        self::runClient($port, $logger, $payload);

        self::assertContains('connected', $logger->lines, 'The hello frame was not logged as read.');
        self::assertSame(self::EXPECTED_PAYLOAD, $payload);
        self::assertAcked($process);
        $this->assertStubStopped($process);
    }

    /**
     * A keepalive ping matters only if the peer answers it and stays connected long enough to read
     * the next real frame on that same socket.
     *
     * @throws Throwable
     * @throws StubSlackException
     */
    #[Test]
    public function answersTheStubPingWithAPongWithoutFallingOffTheConnection(): void
    {
        $port = FreePort::acquire();
        $process = $this->startStub($port, self::MODE_PING);
        $logger = new RecordingLogger();
        /** @var array<string, mixed>|false|null $payload */
        $payload = null;

        self::runClient($port, $logger, $payload);

        self::assertContains('connected', $logger->lines, 'The hello frame was not logged as read.');
        self::assertSame(self::EXPECTED_PAYLOAD, $payload);
        self::assertNotContains(
            'nothing arrived within ' . self::SILENCE_TIMEOUT . 's; reconnecting',
            $logger->lines,
            'The keepalive exchange was treated as silence.',
        );
        self::assertNotNull(
            self::waitForLine($process, 'PONG', self::LINE_TIMEOUT),
            "no pong line arrived: {$process->stderr()}",
        );
        self::assertAcked($process);
        $this->assertStubStopped($process);
    }

    /**
     * A server-side close is only a harmless routine refresh if the next `apps.connections.open`
     * and upgrade really happen.
     *
     * @throws Throwable
     * @throws StubSlackException
     */
    #[Test]
    public function reconnectsAfterTheStubClosesTheSocket(): void
    {
        $port = FreePort::acquire();
        $process = $this->startStub($port, self::MODE_RECONNECT);
        $logger = new RecordingLogger();
        /** @var array<string, mixed>|false|null $payload */
        $payload = null;

        self::runClient($port, $logger, $payload);

        self::assertContains('connected', $logger->lines, 'No connection ever reached the hello frame.');
        self::assertSame(self::EXPECTED_PAYLOAD, $payload);
        self::assertNotNull(
            self::waitForLine($process, 'OPEN 2', self::LINE_TIMEOUT),
            "the second open never arrived: {$process->stderr()}",
        );
        self::assertNotNull(
            self::waitForLine($process, 'UPGRADE 2', self::LINE_TIMEOUT),
            "the second upgrade never arrived: {$process->stderr()}",
        );
        self::assertAcked($process);
        $this->assertStubStopped($process);
    }

    /**
     * @param array<string, mixed>|false|null $payload the one events_api payload the client hands to
     *                                                 the test channel, or `false` when nothing arrived
     *
     * @throws InvalidArgumentException when the literal app token below is malformed
     * @throws Throwable everything the client loop or the connector can raise
     */
    private static function runClient(int $port, RecordingLogger $logger, array|false|null &$payload): void
    {
        Coro::run(
            /**
             * @throws InvalidArgumentException when the literal app token below is malformed
             * @throws Throwable everything the client loop or the connector can raise
             */
            static function () use ($port, $logger, &$payload): void {
                $connector = new SwooleSocketModeConnector(
                    new SlackAppToken('xapp-1-A01234567-0123456789012-stubtest'),
                    new SwooleHttpClientFactory(60.0),
                    apiHost: '127.0.0.1',
                    apiPort: $port,
                );
                $envelopes = new Channel(16);
                $router = new FrameRouter($envelopes, new EnvelopeLog(1000), $logger, handoffTimeout: 0.001);
                $delay = new ReconnectDelay(
                    new Backoff(new MtRandomSource(), base: 1.0, max: 30.0, jitterRatio: 0.5),
                    new CoroutineSleeper(),
                );
                $client = new SocketModeClient(
                    $connector,
                    $router,
                    $delay,
                    $logger,
                    silenceTimeout: self::SILENCE_TIMEOUT,
                );

                $finished = new Channel(1);
                Coroutine::create(static function () use ($client, $finished): void {
                    $client->run();
                    $finished->push(true);
                });

                $payload = $envelopes->pop(5.0);
                $client->stop();
                $finished->pop(5.0);
            },
        );
    }

    /**
     * @throws StubSlackException
     */
    private function startStub(int $port, string $mode = self::MODE_ACK): CliProcess
    {
        $root = dirname(__DIR__, levels: 2);
        $command = [PHP_BINARY, "{$root}/stub-slack/bin/stub-slack", (string) $port];

        if ($mode !== self::MODE_ACK) {
            $command[] = $mode;
        }

        $process = CliProcess::start($command, $root);
        $this->process = $process;
        self::assertNotNull(
            self::waitForLine($process, 'READY', self::READY_TIMEOUT),
            "stub-slack did not report ready: {$process->stderr()}",
        );

        return $process;
    }

    /** Confirms that the stub saw the one acknowledgement frame its scenario expects. */
    private static function assertAcked(CliProcess $process): void
    {
        $ackLine = self::waitForLine($process, 'ACK ', self::LINE_TIMEOUT);
        self::assertNotNull($ackLine, "no ack line arrived: {$process->stderr()}");
        $ack = Json::decode(substr($ackLine, strlen('ACK ')));
        self::assertNotNull($ack);
        self::assertSame('stub-envelope-1', Json::text($ack, 'envelope_id'));
    }

    /** Stops the child process and proves no stub-slack zombie survived this case. */
    private function assertStubStopped(CliProcess $process): void
    {
        $process->stop();
        $this->process = null;
        self::assertSame([], ChildProcesses::all(), 'stub-slack outlived the test.');
    }

    /** Polls the child's stdout, draining both pipes each time, until a line starts with `$prefix`. */
    private static function waitForLine(CliProcess $process, string $prefix, float $timeout): ?string
    {
        $deadline = microtime(as_float: true) + $timeout;
        $now = microtime(as_float: true);

        while ($now < $deadline) {
            // `stderr()` is the only public method that drains stdout as a side effect; there is
            // no method on `CliProcess` for "read whatever stdout has so far" on its own.
            $process->stderr();

            foreach ($process->lines() as $line) {
                if (str_starts_with($line, $prefix)) {
                    return $line;
                }
            }

            usleep(10_000);
            $now = microtime(as_float: true);
        }

        return null;
    }
}
