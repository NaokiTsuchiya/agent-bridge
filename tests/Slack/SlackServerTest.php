<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Pipeline\IncomingMessage;
use NaokiTsuchiya\AgentBridge\Slack\SlackIdentity;
use NaokiTsuchiya\AgentBridge\Slack\SlackIngress;
use NaokiTsuchiya\AgentBridge\Slack\SlackServer;
use NaokiTsuchiya\AgentBridge\Slack\ThreadChannels;
use NaokiTsuchiya\AgentBridge\Tests\Support\Coro;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Swoole\Coroutine\Channel;
use Throwable;

use function array_map;
use function array_slice;

/**
 * The resident half of the Slack front end: a workspace's messages, answered as they arrive.
 *
 * It is a loop meant never to end, so every case here ends it the only way a running one can be
 * ended: by closing the channel it reads from, which answers a parked reader instead of leaving it
 * parked. The connection loop is ended from inside its first attempt ({@see OneAttemptClient}).
 * Nothing waits and nothing below the ports is a mock — a real ingress reads real payloads off a
 * real channel.
 */
final class SlackServerTest extends TestCase
{
    /** This app's own user id, as the workspace sees it. */
    private const string BOT = 'U0BOT';

    /** The `ts` of the first message a case sends, which is also the thread it belongs to. */
    private const string FIRST_TS = '1700000001.000100';

    /** The `ts` of the second one, in a thread of its own. */
    private const string SECOND_TS = '1700000002.000200';

    /**
     * Every message on the queue reaches the chain, as the thread and the text the payload named.
     *
     * @throws Throwable
     */
    #[Test]
    public function answersEveryMessageThatArrives(): void
    {
        $becoming = new ParkingBecoming();

        $run = self::serve([
            self::payload('one', self::FIRST_TS),
            self::payload('two', self::SECOND_TS),
        ], $becoming);

        self::assertSame(
            [
                'slack ' . self::FIRST_TS . ' one',
                'slack ' . self::SECOND_TS . ' two',
            ],
            self::handedOver($becoming->seen),
        );
        self::assertContains('one out', $becoming->record);
        self::assertContains('two out', $becoming->record);
        self::assertSame([], $run->logger->lines);
    }

    /**
     * A message being answered does not hold up the next one.
     *
     * Two threads that are not the same are meant to run at the same time, and a front end that
     * took them in turn would make a whole workspace wait behind whichever agent is slowest.
     *
     * @throws Throwable
     */
    #[Test]
    public function takesUpTheNextMessageWithoutWaitingForTheLast(): void
    {
        $becoming = new ParkingBecoming();

        self::serve([
            self::payload('one', self::FIRST_TS),
            self::payload('two', self::SECOND_TS),
        ], $becoming);

        self::assertSame(
            ['one in', 'two in'],
            array_slice($becoming->record, offset: 0, length: 2),
            'The second turn only began after the first had finished.',
        );
    }

    /**
     * A message that cannot be answered is logged, and the workspace goes on being answered.
     *
     * Nothing may be thrown out of the coroutine a turn runs on: what is thrown inside one ends the
     * process, so a single malformed thread id would take the whole workspace's bot down.
     *
     * @throws Throwable
     */
    #[Test]
    public function keepsGoingWhenAMessageCannotBeAnswered(): void
    {
        $becoming = new ParkingBecoming(['two' => new RuntimeException('boom')]);

        $run = self::serve([
            self::payload('one', self::FIRST_TS),
            self::payload('two', self::SECOND_TS),
            self::payload('three', '1700000003.000300'),
        ], $becoming);

        self::assertSame(['could not answer a message: boom'], $run->logger->lines);
        self::assertContains('one out', $becoming->record);
        self::assertContains('three out', $becoming->record);
        self::assertNotContains('two out', $becoming->record);
    }

    /**
     * The connection is opened alongside the messages rather than before them.
     *
     * Both halves wait on channels, so a server that ran the connection loop first would never
     * reach the queue, and one that reached the queue first would have nothing filling it.
     *
     * @throws Throwable
     */
    #[Test]
    public function opensTheConnectionAlongsideTheMessages(): void
    {
        $run = self::serve([self::payload('one', self::FIRST_TS)], new ParkingBecoming());

        self::assertSame(1, $run->connection->connector->attempts);
        self::assertSame([], $run->connection->sleeper->delays, 'Something took a real wait.');
    }

    /**
     * Closing the queue is what ends the server, which is the only way a resident loop is stopped.
     *
     * @throws Throwable
     */
    #[Test]
    public function stopsWhenTheMessagesRunOut(): void
    {
        $becoming = new ParkingBecoming();

        self::serve([], $becoming);

        self::assertSame([], $becoming->record);
    }

    /**
     * Runs a server over those payloads, which are all the workspace will ever send.
     *
     * @param list<array<array-key, mixed>> $payloads what arrives, oldest first
     *
     * @return ServerRun what the finished run left behind
     *
     * @throws InvalidArgumentException never; the identity below is a usable one.
     * @throws Throwable whatever a case asserted inside the coroutine.
     */
    private static function serve(array $payloads, ParkingBecoming $becoming): ServerRun
    {
        $logger = new RecordingLogger();
        $envelopes = new Channel(16);
        $connection = new OneAttemptClient($envelopes, $logger);
        $server = new SlackServer(
            $becoming,
            new SlackIngress($envelopes, new SlackIdentity(self::BOT), new ThreadChannels()),
            $connection->client,
            $logger,
        );

        Coro::run(static function () use ($payloads, $envelopes, $server): void {
            foreach ($payloads as $payload) {
                $envelopes->push($payload);
            }

            // Closed before the run rather than during it: a closed channel still hands out what is
            // already on it, and it is what tells the server there will be no more.
            $envelopes->close();

            $server->run();
        });

        return new ServerRun($connection, $logger);
    }

    /**
     * @param list<IncomingMessage> $messages
     *
     * @return list<string> each as the three things the pipeline was given
     */
    private static function handedOver(array $messages): array
    {
        return array_map(
            static fn(IncomingMessage $message): string => "{$message->platform} {$message->nativeId} {$message->text}",
            $messages,
        );
    }

    /** @return array<array-key, mixed> an envelope payload carrying a mention from a person */
    private static function payload(string $text, string $ts): array
    {
        return [
            'type' => 'event_callback',
            'event' => [
                'type' => 'app_mention',
                'user' => 'U0HUMAN',
                'channel' => 'C0CHANNEL',
                'ts' => $ts,
                'text' => $text,
            ],
        ];
    }
}
