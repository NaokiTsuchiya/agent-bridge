<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Integration;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Slack\RetryingSlackApiClient;
use NaokiTsuchiya\AgentBridge\Slack\SlackBotToken;
use NaokiTsuchiya\AgentBridge\Slack\SlackEgress;
use NaokiTsuchiya\AgentBridge\Slack\SlackReply;
use NaokiTsuchiya\AgentBridge\Slack\SlackStream;
use NaokiTsuchiya\AgentBridge\Slack\StreamingSettings;
use NaokiTsuchiya\AgentBridge\Slack\SwooleHttpClientFactory;
use NaokiTsuchiya\AgentBridge\Slack\SwooleSlackApiClient;
use NaokiTsuchiya\AgentBridge\Slack\SystemClock;
use NaokiTsuchiya\AgentBridge\Slack\ThreadChannels;
use NaokiTsuchiya\AgentBridge\StubSlack\FreePort;
use NaokiTsuchiya\AgentBridge\StubSlack\SelfSignedCertificate;
use NaokiTsuchiya\AgentBridge\StubSlack\StubApiAnswer;
use NaokiTsuchiya\AgentBridge\StubSlack\StubSlackApi;
use NaokiTsuchiya\AgentBridge\StubSlack\StubSlackException;
use NaokiTsuchiya\AgentBridge\StubSlack\StubSlackScenario;
use NaokiTsuchiya\AgentBridge\StubSlack\StubSlackServer;
use NaokiTsuchiya\AgentBridge\Tests\Slack\RecordingLogger;
use NaokiTsuchiya\AgentBridge\Tests\Slack\RecordingSleeper;
use NaokiTsuchiya\AgentBridge\Tests\Support\Coro;
use NaokiTsuchiya\AgentBridge\Tests\Support\Json;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Exception as SwooleException;
use Throwable;

use function array_column;
use function array_shift;
use function count;

/**
 * The Web API send path — reply, stream, and status — over a real TLS socket to a real separate
 * server, the way {@see SocketModeStubTest} covers the receive path.
 *
 * `chat.postMessage` / `chat.startStream` / `chat.appendStream` / `chat.stopStream` /
 * `assistant.threads.setStatus` are the five methods this repository sends
 * (`StubSlackApi::METHODS`); every production class between a call and the socket runs unmodified —
 * only `SwooleSlackApiClient`'s `apiHost`/`apiPort` point at the stub instead of `slack.com`. No
 * child process is needed here, unlike `SocketModeStubTest`: a Web API call is one request and one
 * answer, not a connection the stub has to keep alive alongside the client's own loop, so the stub
 * and the client both run as coroutines of the one process this test is in.
 */
#[Group('integration')]
final class SlackApiStubTest extends TestCase
{
    /** The thread every case answers in. */
    private const string NATIVE_ID = '1700000001.123456';

    /** Where that thread lives. */
    private const string CHANNEL = 'C0CHANNEL';

    /** Shaped like a real bot token but reaching only the stub. */
    private const string TOKEN = SlackBotToken::PREFIX . 'stubtest';

    /**
     * `chat.startStream` opens, `chat.appendStream` adds twice, `chat.stopStream` ends it — in that
     * order, each carrying only the fragment it sent, and `chat.stopStream` addressed to the `ts`
     * `chat.startStream` answered with (the one thing that ties the three into one conversation,
     * per `Slack\SlackStream`'s own class doc).
     *
     * @throws Throwable
     */
    #[Test]
    public function streamsAReplyInOrderOverARealTlsSocket(): void
    {
        Coro::run(
            /** @throws InvalidArgumentException|StubSlackException|SwooleException */
            static function (): void {
                [$server, $api, $port] = self::stub();
                $stream = new SlackStream(
                    self::client($port),
                    self::CHANNEL,
                    self::NATIVE_ID,
                    new RecordingLogger(),
                    new StreamingSettings(),
                );

                self::assertTrue($stream->send('one '));
                self::assertTrue($stream->send('two '));
                self::assertTrue($stream->send('three'));
                $stream->stop();
                $server->shutdown();

                self::assertSame(
                    [StubSlackApi::START, StubSlackApi::APPEND, StubSlackApi::APPEND, StubSlackApi::STOP],
                    array_column($api->calls, 'method'),
                );

                $calls = $api->calls;

                $started = array_shift($calls);
                self::assertNotNull($started);
                self::assertSame(self::CHANNEL, Json::text($started['arguments'], 'channel'));
                self::assertSame(self::NATIVE_ID, Json::text($started['arguments'], 'thread_ts'));
                self::assertSame('one ', Json::text($started['arguments'], 'markdown_text'));

                $appendedOne = array_shift($calls);
                self::assertNotNull($appendedOne);
                self::assertSame('two ', Json::text($appendedOne['arguments'], 'markdown_text'));

                $appendedTwo = array_shift($calls);
                self::assertNotNull($appendedTwo);
                self::assertSame('three', Json::text($appendedTwo['arguments'], 'markdown_text'));

                $stopped = array_shift($calls);
                self::assertNotNull($stopped);
                self::assertSame(self::CHANNEL, Json::text($stopped['arguments'], 'channel'));
                self::assertSame(StubSlackApi::TS, Json::text($stopped['arguments'], 'ts'));
            },
        );
    }

    /**
     * The non-streaming path — `Slack\SlackReply`, the fallback a workspace that will not open a
     * stream still gets its answer through — posts one `chat.postMessage` with everything appended
     * to it, joined.
     *
     * @throws Throwable
     */
    #[Test]
    public function postsANonStreamingReplyOverARealTlsSocket(): void
    {
        Coro::run(
            /** @throws InvalidArgumentException|StubSlackException|SwooleException */
            static function (): void {
                [$server, $api, $port] = self::stub();
                $reply = new SlackReply(self::client($port), self::CHANNEL, self::NATIVE_ID, new RecordingLogger());

                $reply->append('hello ');
                $reply->append('world');
                $reply->close();
                $server->shutdown();

                self::assertSame([StubSlackApi::POST_MESSAGE], array_column($api->calls, 'method'));

                $calls = $api->calls;
                $posted = array_shift($calls);
                self::assertNotNull($posted);
                self::assertSame(self::CHANNEL, Json::text($posted['arguments'], 'channel'));
                self::assertSame(self::NATIVE_ID, Json::text($posted['arguments'], 'thread_ts'));
                self::assertSame('hello world', Json::text($posted['arguments'], 'text'));
            },
        );
    }

    /**
     * `assistant.threads.setStatus` reaches the stub, carrying the channel, the thread, and the
     * status text `Slack\SlackEgress::status()` was given. The stub answers `ok: true` by default,
     * so the `chat.postMessage` fallback (`SlackEgress.php`'s own doc: shown only when Slack refuses
     * the status call) never fires.
     *
     * @throws Throwable
     */
    #[Test]
    public function showsTheStatusOverARealTlsSocket(): void
    {
        Coro::run(
            /** @throws InvalidArgumentException|StubSlackException|SwooleException */
            static function (): void {
                [$server, $api, $port] = self::stub();
                $channels = new ThreadChannels();
                $channels->remember(self::NATIVE_ID, self::CHANNEL);
                $egress = new SlackEgress(
                    self::client($port),
                    $channels,
                    new RecordingLogger(),
                    new StreamingSettings(),
                    new SystemClock(),
                );

                $egress->status(new ThreadId('slack:' . self::NATIVE_ID), 'Working on it.');
                $server->shutdown();

                self::assertCount(1, $api->calls, 'the setStatus refusal fell back to a message');

                $calls = $api->calls;
                $shown = array_shift($calls);
                self::assertNotNull($shown);
                self::assertSame(StubSlackApi::SET_STATUS, $shown['method']);
                self::assertSame(self::CHANNEL, Json::text($shown['arguments'], 'channel_id'));
                self::assertSame(self::NATIVE_ID, Json::text($shown['arguments'], 'thread_ts'));
                self::assertSame('Working on it.', Json::text($shown['arguments'], 'status'));
            },
        );
    }

    /**
     * A real 429, with a real `Retry-After` header, over the real socket, makes
     * `Slack\RetryingSlackApiClient` wait that long and call again — and the second call really
     * does reach the stub a second time, which a fake transport could not show.
     *
     * @throws Throwable
     */
    #[Test]
    public function retriesARateLimitedCallOverARealTlsSocket(): void
    {
        Coro::run(
            /** @throws InvalidArgumentException|StubSlackException|SwooleException */
            static function (): void {
                [$server, $api, $port] = self::stub();
                $api->script(
                    StubSlackApi::POST_MESSAGE,
                    new StubApiAnswer(
                        status: 429,
                        body: ['ok' => false, 'error' => 'ratelimited'],
                        headers: ['Retry-After' => '0.05'],
                    ),
                );
                $sleeper = new RecordingSleeper();
                $transport = new SwooleSlackApiClient(
                    new SlackBotToken(self::TOKEN),
                    new SwooleHttpClientFactory(),
                    apiHost: '127.0.0.1',
                    apiPort: $port,
                );
                $client = new RetryingSlackApiClient($transport, $sleeper, new StreamingSettings());

                $result = $client->call(StubSlackApi::POST_MESSAGE, ['channel' => self::CHANNEL, 'text' => 'hi']);
                $server->shutdown();

                self::assertTrue($result->ok, $result->error);
                self::assertSame([0.05], $sleeper->delays);
                self::assertSame(2, count($api->calls), 'the retry did not reach the stub a second time');
            },
        );
    }

    /**
     * @return array{StubSlackServer, StubSlackApi, int}
     *
     * @throws StubSlackException when a port cannot be reserved or the certificate cannot be built
     * @throws SwooleException when the TLS listener cannot be bound
     */
    private static function stub(): array
    {
        $port = FreePort::acquire();
        $api = new StubSlackApi();
        $server = new StubSlackServer(
            '127.0.0.1',
            $port,
            SelfSignedCertificate::generate(),
            new StubSlackScenario('{"type":"hello","num_connections":1}', '{}'),
            static function (string $_ack): void {},
            $api,
        );

        Coroutine::create($server->start(...));

        return [$server, $api, $port];
    }

    /** @throws InvalidArgumentException when the literal token above is malformed */
    private static function client(int $port): RetryingSlackApiClient
    {
        $transport = new SwooleSlackApiClient(
            new SlackBotToken(self::TOKEN),
            new SwooleHttpClientFactory(),
            apiHost: '127.0.0.1',
            apiPort: $port,
        );

        return new RetryingSlackApiClient($transport, new RecordingSleeper(), new StreamingSettings());
    }
}
