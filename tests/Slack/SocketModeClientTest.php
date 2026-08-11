<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Slack\Backoff;
use NaokiTsuchiya\AgentBridge\Slack\EnvelopeLog;
use NaokiTsuchiya\AgentBridge\Slack\FrameRouter;
use NaokiTsuchiya\AgentBridge\Slack\ReconnectDelay;
use NaokiTsuchiya\AgentBridge\Slack\SocketModeClient;
use NaokiTsuchiya\AgentBridge\Slack\SocketModeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Channel;

use function count;
use function Swoole\Coroutine\run;

/**
 * Drives the whole loop through a fake connection: no WebSocket, no token, no real waiting.
 *
 * @mago-expect lint:too-many-methods
 *
 * @internal
 */
final class SocketModeClientTest extends TestCase
{
    /** Passed to every receive, and asserted on where the silence timeout is the subject. */
    private const float SILENCE_TIMEOUT = 60.0;

    /** The shortest backoff, which is also the whole delay after a connection that worked. */
    private const float BASE_DELAY = 1.0;

    /** The ceiling the doubling stops at. */
    private const float MAX_DELAY = 8.0;

    /** An events_api frame as Slack sends it. */
    private const string EVENT = '{"type":"events_api","envelope_id":"ev-1","payload":{"event":{"type":"app_mention"}}}';

    /** The acknowledgement the frame above is owed. */
    private const string ACK = '{"envelope_id":"ev-1"}';

    /** The hello frame is only ever logged, so the proof it was read is that reading went on. */
    #[Test]
    public function keepsReadingAfterTheHelloFrame(): void
    {
        $connection = new FakeSocketModeConnection(['{"type":"hello","num_connections":1}', self::EVENT]);

        $outcome = self::drive([$connection], stopAt: 2);

        self::assertSame([self::ACK], $connection->sent);
        self::assertContains('connected', $outcome->logger->lines);
    }

    /** The two things an events_api frame is owed: an ack with its id, and its payload downstream. */
    #[Test]
    public function acknowledgesAnEventsApiFrameAndHandsThePayloadOn(): void
    {
        $connection = new FakeSocketModeConnection([self::EVENT]);

        $outcome = self::drive([$connection], stopAt: 2);

        self::assertSame([self::ACK], $connection->sent);
        self::assertSame([['event' => ['type' => 'app_mention']]], $outcome->handedOn);
    }

    /**
     * The ack owes nothing to the consumer: with the channel full and nobody draining it, the
     * envelope is still acknowledged, and the loop moves on to the next frame.
     */
    #[Test]
    public function acknowledgesWhileTheChannelIsFull(): void
    {
        $connection = new FakeSocketModeConnection([self::EVENT, '{"type":"disconnect","reason":"warning"}']);

        $outcome = self::drive([$connection], stopAt: 2, capacity: 1, prefill: 1);

        self::assertSame([self::ACK], $connection->sent);
        self::assertSame(1, $outcome->channelLength, 'Nothing was added to the full channel.');
        self::assertSame([['filler' => 0]], $outcome->handedOn);
        self::assertContains(
            'acknowledged ev-1, but the downstream channel would not take it',
            $outcome->logger->lines,
        );
        self::assertSame(2, $outcome->connector->attempts, 'The disconnect after it was still read.');
    }

    /** A redelivery is acknowledged again — silence would have Slack repeat it forever — but not passed on twice. */
    #[Test]
    public function handsOnARepeatedEnvelopeOnlyOnce(): void
    {
        $connection = new FakeSocketModeConnection([self::EVENT, self::EVENT]);

        $outcome = self::drive([$connection], stopAt: 2);

        self::assertSame([self::ACK, self::ACK], $connection->sent);
        self::assertSame([['event' => ['type' => 'app_mention']]], $outcome->handedOn);
    }

    /** Slack cycles connections on purpose, so a disconnect frame is answered with a new connection. */
    #[Test]
    public function reconnectsAfterADisconnectFrame(): void
    {
        $connection = new FakeSocketModeConnection(['{"type":"disconnect","reason":"refresh_requested"}']);

        $outcome = self::drive([$connection], stopAt: 2);

        self::assertSame(2, $outcome->connector->attempts);
        self::assertSame(1, $connection->closes, 'The old connection is released, not left dangling.');
        self::assertSame([self::BASE_DELAY], $outcome->sleeper->delays);
    }

    /** A connection that produces nothing at all is discarded once the silence timeout is reached. */
    #[Test]
    public function reconnectsAfterTheSilenceTimeout(): void
    {
        $connection = new FakeSocketModeConnection();

        $outcome = self::drive([$connection], stopAt: 2);

        self::assertSame([self::SILENCE_TIMEOUT], $connection->timeouts);
        self::assertSame(1, $connection->closes);
        self::assertSame(2, $outcome->connector->attempts);
    }

    /** A connection that breaks mid-stream is the same story as a disconnect, told by an exception. */
    #[Test]
    public function reconnectsWhenTheConnectionBreaks(): void
    {
        $connection = new FakeSocketModeConnection([new SocketModeException('reset by peer')]);

        $outcome = self::drive([$connection], stopAt: 2);

        self::assertSame(2, $outcome->connector->attempts);
        self::assertSame(1, $connection->closes);
        self::assertContains('connection lost: reset by peer', $outcome->logger->lines);
    }

    /** An ack that cannot be sent says the connection is gone, whatever it looked like a moment ago. */
    #[Test]
    public function reconnectsWhenTheAcknowledgementCannotBeSent(): void
    {
        $connection = new FakeSocketModeConnection([self::EVENT]);
        $connection->sendFailure = new SocketModeException('broken pipe');

        $outcome = self::drive([$connection], stopAt: 2);

        self::assertSame([], $connection->sent);
        self::assertSame([], $outcome->handedOn);
        self::assertSame(2, $outcome->connector->attempts);
        self::assertSame(1, $connection->closes);
    }

    /** Each failed attempt waits longer than the last, and the ladder stops at the ceiling. */
    #[Test]
    public function waitsLongerAfterEachFailedAttemptUpToTheCeiling(): void
    {
        $failures = [
            new SocketModeException('invalid_auth'),
            new SocketModeException('invalid_auth'),
            new SocketModeException('invalid_auth'),
            new SocketModeException('invalid_auth'),
            new SocketModeException('invalid_auth'),
        ];

        $outcome = self::drive($failures, stopAt: 6);

        self::assertSame([1.0, 2.0, 4.0, 8.0, 8.0], $outcome->sleeper->delays);
        self::assertSame([], $outcome->handedOn);
    }

    /** A connection that was established says nothing about Slack being unreachable: the ladder resets. */
    #[Test]
    public function startsTheLadderOverAfterAConnectionThatWorked(): void
    {
        $connections = [
            new SocketModeException('service_unavailable'),
            new SocketModeException('service_unavailable'),
            new FakeSocketModeConnection(['{"type":"disconnect","reason":"warning"}']),
        ];

        $outcome = self::drive($connections, stopAt: 4);

        self::assertSame([1.0, 2.0, 1.0], $outcome->sleeper->delays);
    }

    /**
     * A frame that cannot be read is not worth a connection: the next one is still handled.
     *
     * @throws SocketModeException
     */
    #[DataProvider('unreadableFrames')]
    #[Test]
    public function keepsTheConnectionAfterAFrameItCannotUse(string $frame): void
    {
        $connection = new FakeSocketModeConnection([$frame, self::EVENT]);

        $outcome = self::drive([$connection], stopAt: 2);

        self::assertSame([self::ACK], $connection->sent, 'Only the events_api frame is acknowledged.');
        self::assertSame([['event' => ['type' => 'app_mention']]], $outcome->handedOn);
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableFrames(): iterable
    {
        yield 'truncated json' => ['{'];
        yield 'empty' => [''];
        yield 'a json array' => ['[]'];
        yield 'a json string' => ['"events_api"'];
        yield 'an unknown type' => ['{"type":"nope","payload":"events_api"}'];
        yield 'no type at all' => ['{"envelope_id":"ev-9"}'];
        yield 'a type that is not a string' => ['{"type":1,"envelope_id":"ev-9"}'];
    }

    /** Without a usable envelope id there is nothing to acknowledge, and nothing to be idempotent about. */
    #[DataProvider('framesWithoutAnEnvelopeId')]
    #[Test]
    public function ignoresAnEventsApiFrameWithoutAUsableEnvelopeId(string $frame): void
    {
        $connection = new FakeSocketModeConnection([$frame, self::EVENT]);

        $outcome = self::drive([$connection], stopAt: 2);

        self::assertSame([self::ACK], $connection->sent);
        self::assertSame(1, count($outcome->handedOn));
    }

    /** @return iterable<string, array{string}> */
    public static function framesWithoutAnEnvelopeId(): iterable
    {
        yield 'missing' => ['{"type":"events_api","payload":{"a":1}}'];
        yield 'null' => ['{"type":"events_api","envelope_id":null,"payload":{"a":1}}'];
        yield 'a number' => ['{"type":"events_api","envelope_id":42,"payload":{"a":1}}'];
        yield 'empty' => ['{"type":"events_api","envelope_id":"","payload":{"a":1}}'];
    }

    /** A payload that is not an object is acknowledged all the same; there is just nothing to hand on. */
    #[DataProvider('framesWithoutAPayloadObject')]
    #[Test]
    public function acknowledgesAFrameWhosePayloadCannotBeHandedOn(string $frame): void
    {
        $connection = new FakeSocketModeConnection([$frame, '{"type":"disconnect"}']);

        $outcome = self::drive([$connection], stopAt: 2);

        self::assertSame(['{"envelope_id":"ev-7"}'], $connection->sent);
        self::assertSame([], $outcome->handedOn);
        self::assertContains('acknowledged ev-7, which carries no payload object', $outcome->logger->lines);
    }

    /** @return iterable<string, array{string}> */
    public static function framesWithoutAPayloadObject(): iterable
    {
        yield 'missing' => ['{"type":"events_api","envelope_id":"ev-7"}'];
        yield 'a string' => ['{"type":"events_api","envelope_id":"ev-7","payload":"app_mention"}'];
        yield 'a number' => ['{"type":"events_api","envelope_id":"ev-7","payload":7}'];
        yield 'null' => ['{"type":"events_api","envelope_id":"ev-7","payload":null}'];
    }

    /** A stop asked for while a connection was up ends the loop instead of opening another one. */
    #[Test]
    public function opensNoFurtherConnectionAfterAStop(): void
    {
        $connection = new FakeSocketModeConnection(['{"type":"disconnect","reason":"warning"}']);

        $outcome = self::drive([$connection], stopAt: 1);

        self::assertSame(1, $outcome->connector->attempts);
        self::assertSame([], $outcome->sleeper->delays, 'A shutdown does not wait out a backoff.');
        self::assertSame(1, $connection->closes);
    }

    /**
     * Runs the client until the connector is asked for the `$stopAt`-th connection.
     *
     * Everything that touches the channel happens inside the coroutine, including draining it:
     * outside one, `Swoole\Coroutine\Channel` refuses to work at all.
     *
     * @param list<FakeSocketModeConnection|SocketModeException> $connections
     * @param int                                                $prefill how many items to leave on
     *                                                                    the channel before the run,
     *                                                                    to stand for a stalled consumer
     */
    private static function drive(array $connections, int $stopAt, int $capacity = 4, int $prefill = 0): RunOutcome
    {
        $sleeper = new RecordingSleeper();
        $logger = new RecordingLogger();
        // Written from inside the coroutine below, and read from the hook that ends the run; the
        // hook fires on the first attempt too, when there is no client yet.
        /** @var SocketModeClient|null $client */
        $client = null;
        $connector = new FakeSocketModeConnector($connections, static function (int $attempt) use (
            &$client,
            $stopAt,
        ): void {
            if ($attempt < $stopAt || !$client instanceof SocketModeClient) {
                return;
            }

            $client->stop();
        });

        /** @var list<mixed> $handedOn */
        $handedOn = [];
        $length = 0;

        run(static function () use (
            $connector,
            &$client,
            $sleeper,
            $logger,
            $capacity,
            $prefill,
            &$handedOn,
            &$length,
        ): void {
            $channel = new Channel($capacity);

            for ($i = 0; $i < $prefill; $i++) {
                $channel->push(['filler' => $i], 0.001);
            }

            try {
                $seen = new EnvelopeLog();
            } catch (InvalidArgumentException $exception) {
                // The capacity is the class' own default, so this cannot happen. It is caught
                // rather than let out because an exception leaving a coroutine kills the
                // process, which would take the test output with it.
                $logger->log($exception->getMessage());

                return;
            }

            $client = new SocketModeClient(
                $connector,
                new FrameRouter($channel, $seen, $logger),
                new ReconnectDelay(
                    new Backoff(new FixedRandomSource(), base: self::BASE_DELAY, max: self::MAX_DELAY),
                    $sleeper,
                ),
                $logger,
                self::SILENCE_TIMEOUT,
            );
            $client->run();

            $length = $channel->length();

            // `pop` answers false when there is nothing left, which nothing here ever pushes.
            for ($item = $channel->pop(0.001); $item !== false; $item = $channel->pop(0.001)) {
                $handedOn[] = $item;
            }
        });

        return new RunOutcome($connector, $sleeper, $logger, $handedOn, $length);
    }
}
