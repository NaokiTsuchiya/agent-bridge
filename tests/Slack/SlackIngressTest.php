<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Chat\ChatIngress;
use NaokiTsuchiya\AgentBridge\Pipeline\IncomingMessage;
use NaokiTsuchiya\AgentBridge\Slack\SlackIdentity;
use NaokiTsuchiya\AgentBridge\Slack\SlackIngress;
use NaokiTsuchiya\AgentBridge\Slack\ThreadChannels;
use NaokiTsuchiya\AgentBridge\Tests\Support\Coro;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Channel;
use Throwable;

/**
 * The queue the Socket Mode client fills, read as the messages the application answers.
 *
 * Driven through the port itself rather than through {@see \NaokiTsuchiya\AgentBridge\Slack\SlackMessage}:
 * what is asserted here is that a payload put on the channel comes out of `listen()` as something
 * the pipeline accepts, and that the channel a reply will need was written down on the way past.
 */
final class SlackIngressTest extends TestCase
{
    /** This app's own user id, as the workspace sees it. */
    private const string BOT = 'U0BOT';

    /** The `ts` of the message that starts the thread. */
    private const string PARENT_TS = '1700000001.123456';

    /**
     * A mention becomes a message for the pipeline, with the two parts of a thread id as strings.
     *
     * @throws Throwable
     */
    #[Test]
    public function enumeratesWhatWasPutOnTheChannel(): void
    {
        $channels = new ThreadChannels();

        $messages = $this->listen([self::payload(['ts' => self::PARENT_TS, 'text' => 'hello'])], $channels);

        self::assertCount(1, $messages);
        $message = $messages[0] ?? null;
        self::assertInstanceOf(IncomingMessage::class, $message);
        self::assertSame('slack', $message->platform);
        self::assertSame(self::PARENT_TS, $message->nativeId);
        self::assertSame('hello', $message->text);
    }

    /**
     * The channel is written down before the message goes out, because the status shown on the way
     * in is the first thing that needs it.
     *
     * @throws Throwable
     */
    #[Test]
    public function remembersWhereTheThreadLives(): void
    {
        $channels = new ThreadChannels();

        $this->listen([self::payload(['ts' => self::PARENT_TS])], $channels);

        self::assertSame('C0CHANNEL', $channels->channelFor(self::PARENT_TS));
    }

    /**
     * An event this front end has no answer for produces no message at all — it is not passed on
     * as an empty one, and it does not stop the messages after it.
     *
     * @throws Throwable
     */
    #[Test]
    public function producesNothingForAnEventItDoesNotAnswer(): void
    {
        $channels = new ThreadChannels();

        $messages = $this->listen([
            self::payload(['ts' => self::PARENT_TS, 'type' => 'reaction_added']),
            self::payload(['ts' => self::PARENT_TS, 'bot_id' => 'B0APP']),
            self::payload(['ts' => self::PARENT_TS, 'user' => self::BOT]),
        ], $channels);

        self::assertSame([], $messages);
        self::assertNull($channels->channelFor(self::PARENT_TS), 'Nothing was answered, so nothing was noted.');
    }

    /**
     * The front end is one of the three ports, which is what makes it swappable at all.
     *
     * @throws InvalidArgumentException never; the identity below is a usable one
     */
    #[Test]
    public function isTheIngressPort(): void
    {
        self::assertInstanceOf(
            ChatIngress::class,
            new SlackIngress(new Channel(1), new SlackIdentity(self::BOT), new ThreadChannels()),
        );
    }

    /**
     * Runs the ingress over a channel that is closed once the payloads are on it.
     *
     * Closing is how a listening front end is asked to stop: it answers every waiting reader at
     * once, so the generator ends instead of parking forever.
     *
     * @param list<array<array-key, mixed>> $payloads
     *
     * @return list<IncomingMessage> everything the front end made of them
     *
     * @throws Throwable
     */
    private function listen(array $payloads, ThreadChannels $channels): array
    {
        $envelopes = new Channel(16);
        $ingress = new SlackIngress($envelopes, new SlackIdentity(self::BOT), $channels);

        $messages = [];
        Coro::run(static function () use ($envelopes, $ingress, $payloads, &$messages): void {
            foreach ($payloads as $payload) {
                $envelopes->push($payload);
            }

            $envelopes->close();

            foreach ($ingress->listen() as $message) {
                $messages[] = $message;
            }
        });

        $answered = [];
        foreach ($messages as $message) {
            self::assertInstanceOf(IncomingMessage::class, $message);
            $answered[] = $message;
        }

        return $answered;
    }

    /**
     * @param array<array-key, mixed> $overrides
     *
     * @return array<array-key, mixed> an envelope payload carrying a mention from a person
     */
    private static function payload(array $overrides): array
    {
        return [
            'type' => 'event_callback',
            'event' => [
                'type' => 'app_mention',
                'user' => 'U0HUMAN',
                'channel' => 'C0CHANNEL',
                'text' => 'hello',
                ...$overrides,
            ],
        ];
    }
}
