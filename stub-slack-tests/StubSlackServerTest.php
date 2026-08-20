<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\StubSlack;

use NaokiTsuchiya\AgentBridge\Support\Coro;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;
use Throwable;

use function json_decode;
use function json_encode;

/**
 * Drives {@see StubSlackServer} directly with a raw coroutine HTTP client — no production Socket
 * Mode code involved. That round trip is {@see \NaokiTsuchiya\AgentBridge\Integration\SocketModeStubTest}'s
 * job; this is the stub answering for itself.
 *
 * @internal
 */
final class StubSlackServerTest extends TestCase
{
    /** As Slack sends `hello`; only its `type` matters to anything that reads it. */
    private const string HELLO = '{"type":"hello","num_connections":1}';

    /** An events_api frame carrying the envelope a caller's ack is expected to name. */
    private const string EVENT = '{"type":"events_api","envelope_id":"ev-1","payload":{"event":{"type":"app_mention"}}}';

    /** Nothing in these three tests cares what happens to an ack; the sink is a no-op. */
    private static function ignoringAcks(): StubSlackScenario
    {
        return new StubSlackScenario(self::HELLO, self::EVENT);
    }

    /**
     * The URL `apps.connections.open` answers with has to point back at this same stub.
     *
     * @throws Throwable
     */
    #[Test]
    public function connectionsOpenAnswersWithAWssUrlNamingItself(): void
    {
        Coro::run(self::connectionsOpenScenario(...));
    }

    /**
     * hello then the events_api frame, in that order — a client reading out of order would misbehave.
     *
     * @throws Throwable
     */
    #[Test]
    public function sendsHelloThenTheEventsFrameOverTheUpgradedConnection(): void
    {
        Coro::run(self::helloThenEventsScenario(...));
    }

    /**
     * The ack sink is handed the raw frame the client sent, unmodified.
     *
     * @throws Throwable
     */
    #[Test]
    public function handsTheAckToTheInjectedSink(): void
    {
        /** @var string|null $captured */
        $captured = null;

        Coro::run(
            /** @throws StubSlackException|Client\Exception|\Swoole\Exception */
            static function () use (&$captured): void {
                self::ackScenario($captured);
            },
        );

        self::assertSame('{"envelope_id":"ev-1"}', $captured);
    }

    /** @throws StubSlackException|Client\Exception|\Swoole\Exception */
    private static function connectionsOpenScenario(): void
    {
        $port = FreePort::acquire();
        $server = new StubSlackServer(
            '127.0.0.1',
            $port,
            SelfSignedCertificate::generate(),
            self::ignoringAcks(),
            static function (string $_ack): void {},
            new StubSlackApi(),
        );

        Coroutine::create($server->start(...));

        $client = new Client('127.0.0.1', $port, ssl: true);
        $posted = $client->post('/api/apps.connections.open', '');
        $rawBody = $client->getBody();
        $client->close();
        $server->shutdown();

        self::assertTrue($posted, $client->errMsg);
        self::assertNotFalse($rawBody);
        /** @var array<string, mixed> $body */
        $body = json_decode($rawBody, associative: true);
        self::assertIsArray($body);
        self::assertTrue($body['ok'] ?? null);
        self::assertSame("wss://127.0.0.1:{$port}/socket-mode", $body['url'] ?? null);
    }

    /** @throws StubSlackException|Client\Exception|\Swoole\Exception */
    private static function helloThenEventsScenario(): void
    {
        $port = FreePort::acquire();
        $server = new StubSlackServer(
            '127.0.0.1',
            $port,
            SelfSignedCertificate::generate(),
            self::ignoringAcks(),
            static function (string $_ack): void {},
            new StubSlackApi(),
        );

        Coroutine::create($server->start(...));

        $client = new Client('127.0.0.1', $port, ssl: true);
        $upgraded = $client->upgrade('/socket-mode');
        $first = $client->recv(5.0);
        $second = $client->recv(5.0);
        $client->close();
        $server->shutdown();

        self::assertTrue($upgraded, $client->errMsg);
        self::assertInstanceOf(Frame::class, $first);
        self::assertInstanceOf(Frame::class, $second);
        self::assertSame(self::HELLO, $first->data);
        self::assertSame(self::EVENT, $second->data);
    }

    /** @throws StubSlackException|Client\Exception|\Swoole\Exception */
    private static function ackScenario(?string &$captured): void
    {
        $port = FreePort::acquire();
        $server = new StubSlackServer(
            '127.0.0.1',
            $port,
            SelfSignedCertificate::generate(),
            self::ignoringAcks(),
            static function (string $ack) use (&$captured): void {
                $captured = $ack;
            },
            new StubSlackApi(),
        );

        Coroutine::create($server->start(...));

        $client = new Client('127.0.0.1', $port, ssl: true);
        $client->upgrade('/socket-mode');
        $client->recv(5.0);
        $client->recv(5.0);
        $ack = json_encode(['envelope_id' => 'ev-1']);
        self::assertNotFalse($ack);
        $client->push($ack);
        Coroutine::sleep(0.1);
        $client->close();
        $server->shutdown();
    }
}
