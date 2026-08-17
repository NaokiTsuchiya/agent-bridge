<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Integration;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Slack\Backoff;
use NaokiTsuchiya\AgentBridge\Slack\CoroutineSleeper;
use NaokiTsuchiya\AgentBridge\Slack\EnvelopeLog;
use NaokiTsuchiya\AgentBridge\Slack\FrameRouter;
use NaokiTsuchiya\AgentBridge\Slack\MtRandomSource;
use NaokiTsuchiya\AgentBridge\Slack\ReconnectDelay;
use NaokiTsuchiya\AgentBridge\Slack\SlackAppToken;
use NaokiTsuchiya\AgentBridge\Slack\SocketModeClient;
use NaokiTsuchiya\AgentBridge\Slack\SwooleHttpClientFactory;
use NaokiTsuchiya\AgentBridge\Slack\SwooleSocketModeConnector;
use NaokiTsuchiya\AgentBridge\StubSlack\FreePort;
use NaokiTsuchiya\AgentBridge\StubSlack\StubSlackException;
use NaokiTsuchiya\AgentBridge\Tests\Slack\RecordingLogger;
use NaokiTsuchiya\AgentBridge\Tests\Support\ChildProcesses;
use NaokiTsuchiya\AgentBridge\Tests\Support\CliProcess;
use NaokiTsuchiya\AgentBridge\Tests\Support\Coro;
use NaokiTsuchiya\AgentBridge\Tests\Support\Json;
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
 * `tests/Slack/` cannot show, and `docs/slack-socket-mode.md`'s manual runbook used to be the only
 * way to see at all (steps 4 and 5 there).
 *
 * Every production class between the app token and the delivered payload runs unmodified: only
 * `SwooleSocketModeConnector`'s `apiHost`/`apiPort` point at the stub instead of `slack.com`.
 */
#[Group('integration')]
final class SocketModeStubTest extends TestCase
{
    /** How long the child is given to print its readiness line. */
    private const float READY_TIMEOUT = 5.0;

    /** How long the ack line is given to appear on the child's stdout once sent. */
    private const float ACK_TIMEOUT = 2.0;

    /** Short enough that {@see SocketModeClient::stop()} ends the run deterministically and fast. */
    private const float SILENCE_TIMEOUT = 0.5;

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
        $root = dirname(__DIR__, levels: 2);
        $port = FreePort::acquire();

        $process = CliProcess::start([PHP_BINARY, "{$root}/stub-slack/bin/stub-slack", (string) $port], $root);
        $this->process = $process;
        self::assertNotNull(
            $this->waitForLine($process, 'READY', self::READY_TIMEOUT),
            "stub-slack did not report ready: {$process->stderr()}",
        );

        $logger = new RecordingLogger();
        $payload = null;

        Coro::run(
            /**
             * @throws InvalidArgumentException when the literal app token below is malformed
             * @throws Throwable everything the client loop or the connector can raise
             */
            static function () use ($port, $logger, &$payload): void {
                $connector = new SwooleSocketModeConnector(
                    new SlackAppToken('xapp-1-A01234567-0123456789012-stubtest'),
                    new SwooleHttpClientFactory(),
                    apiHost: '127.0.0.1',
                    apiPort: $port,
                );
                $envelopes = new Channel(16);
                $router = new FrameRouter($envelopes, new EnvelopeLog(), $logger);
                $delay = new ReconnectDelay(new Backoff(new MtRandomSource()), new CoroutineSleeper());
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

        self::assertContains('connected', $logger->lines, 'The hello frame was not logged as read.');
        self::assertSame(['event' => ['type' => 'app_mention', 'text' => 'ping']], $payload);

        $ackLine = $this->waitForLine($process, 'ACK ', self::ACK_TIMEOUT);
        self::assertNotNull($ackLine, "no ack line arrived: {$process->stderr()}");
        $ack = Json::decode(substr($ackLine, strlen('ACK ')));
        self::assertNotNull($ack);
        self::assertSame('stub-envelope-1', Json::text($ack, 'envelope_id'));

        $process->stop();
        $this->process = null;
        self::assertSame([], ChildProcesses::all(), 'stub-slack outlived the test.');
    }

    /** Polls the child's stdout, draining both pipes each time, until a line starts with `$prefix`. */
    private function waitForLine(CliProcess $process, string $prefix, float $timeout): ?string
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
