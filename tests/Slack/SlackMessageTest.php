<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use NaokiTsuchiya\AgentBridge\Slack\SlackMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which payloads are worth answering, and which thread each one belongs to.
 *
 * Every rule the Slack front end has about incoming events is decided here, so this is where they
 * are pinned: the two ways of recognising this app's own posts, the event types that are not
 * questions, and above all the thread id — the value the session and the worktree are derived from.
 *
 * @mago-expect lint:too-many-methods
 */
final class SlackMessageTest extends TestCase
{
    /** This app's own user id, as the workspace sees it. */
    private const string BOT = 'U0BOT';

    /** Somebody who is not this app. */
    private const string HUMAN = 'U0HUMAN';

    /** The `ts` of the message that started the thread; the second vector of docs/poc-design.md. */
    private const string PARENT_TS = '1700000001.123456';

    /** A message posted inside a thread carries the thread's `ts`, and is answered in that thread. */
    #[Test]
    public function takesTheThreadTsAsTheThreadId(): void
    {
        $message = self::read(self::event(['ts' => '1700000009.000000', 'thread_ts' => self::PARENT_TS]));

        self::assertInstanceOf(SlackMessage::class, $message);
        self::assertSame(self::PARENT_TS, $message->nativeId);
        self::assertSame('slack', SlackMessage::PLATFORM);
    }

    /** The first message of a thread has no `thread_ts`, so it names the thread itself. */
    #[Test]
    public function takesItsOwnTsWhenThereIsNoThreadTs(): void
    {
        $message = self::read(self::event(['ts' => self::PARENT_TS]));

        self::assertInstanceOf(SlackMessage::class, $message);
        self::assertSame(self::PARENT_TS, $message->nativeId);
    }

    /**
     * The two rules above are one rule: a reply and the message it replies to are the same thread,
     * which is what makes them the same session and the same worktree.
     */
    #[Test]
    public function resolvesAReplyToTheThreadItAnswers(): void
    {
        $opening = self::read(self::event(['ts' => self::PARENT_TS]));
        $reply = self::read(self::event(['ts' => '1700000002.222222', 'thread_ts' => self::PARENT_TS]));

        self::assertInstanceOf(SlackMessage::class, $opening);
        self::assertInstanceOf(SlackMessage::class, $reply);
        self::assertSame($opening->nativeId, $reply->nativeId);
    }

    /**
     * A `thread_ts` that is there but says nothing is not a thread; the message names its own.
     *
     * @param string|int $threadTs what the payload carries under that key
     */
    #[DataProvider('uselessThreadTs')]
    #[Test]
    public function fallsBackToItsOwnTs(string|int $threadTs): void
    {
        $message = self::read(self::event(['ts' => self::PARENT_TS, 'thread_ts' => $threadTs]));

        self::assertInstanceOf(SlackMessage::class, $message);
        self::assertSame(self::PARENT_TS, $message->nativeId);
    }

    /** @return iterable<string, array{string|int}> */
    public static function uselessThreadTs(): iterable
    {
        yield 'empty' => [''];
        yield 'not a string' => [1_700_000_001];
    }

    /** What the message asked, and where the answer has to go. */
    #[Test]
    public function carriesTheTextAndTheChannel(): void
    {
        $message = self::read(self::event(['ts' => self::PARENT_TS, 'text' => '<@U0BOT> hello']));

        self::assertInstanceOf(SlackMessage::class, $message);
        self::assertSame('<@U0BOT> hello', $message->text);
        self::assertSame('C0CHANNEL', $message->channel);
    }

    /** A message with no text is still a message; the pipeline decides what an empty ask means. */
    #[Test]
    public function acceptsAMessageWithoutText(): void
    {
        $message = self::read([
            'type' => 'app_mention',
            'user' => self::HUMAN,
            'channel' => 'C0CHANNEL',
            'ts' => self::PARENT_TS,
        ]);

        self::assertInstanceOf(SlackMessage::class, $message);
        self::assertSame('', $message->text);
    }

    /**
     * Both kinds of message this app answers.
     *
     * @param string $type what the event calls itself
     */
    #[DataProvider('answerableTypes')]
    #[Test]
    public function answersMentionsAndMessages(string $type): void
    {
        self::assertInstanceOf(
            SlackMessage::class,
            self::read(self::event(['type' => $type, 'ts' => self::PARENT_TS])),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function answerableTypes(): iterable
    {
        yield 'a mention in a channel' => ['app_mention'];
        yield 'a direct message or a thread reply' => ['message'];
    }

    /**
     * A user id this app's own is a prefix of is somebody else.
     *
     * The whole of the check is an identity, and an implementation that asked whether the id merely
     * contained or started with this app's would go silent on that person.
     */
    #[Test]
    public function answersSomebodyWhoseIdStartsLikeTheBots(): void
    {
        $message = self::read(self::event(['user' => self::BOT . 'X', 'ts' => self::PARENT_TS]));

        self::assertInstanceOf(SlackMessage::class, $message);
    }

    /**
     * Everything that is not a question this app has to answer.
     *
     * @param array<array-key, mixed> $payload the whole envelope payload, as Socket Mode delivers it
     */
    #[DataProvider('unanswerable')]
    #[Test]
    public function readsNothingOutOf(array $payload): void
    {
        self::assertNull(SlackMessage::from($payload, self::BOT));
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function unanswerable(): iterable
    {
        $event = self::event(['ts' => self::PARENT_TS]);

        yield 'an envelope of another kind' => [['type' => 'url_verification', 'event' => $event]];
        yield 'an envelope that does not say what it is' => [['event' => $event]];
        yield 'an envelope whose type is not a string' => [['type' => ['event_callback'], 'event' => $event]];
        yield 'an envelope with no event' => [['type' => 'event_callback']];
        yield 'an envelope whose event is not an object' => [['type' => 'event_callback', 'event' => 'hello']];

        yield 'a reaction' => [self::payload([...$event, 'type' => 'reaction_added'])];
        yield 'somebody joining' => [self::payload([...$event, 'type' => 'member_joined_channel'])];
        yield 'an event that does not say what it is' => [self::payload(self::without($event, 'type'))];

        yield 'an edit of an earlier message' => [self::payload([...$event, 'subtype' => 'message_changed'])];
        yield 'a join notice in message form' => [self::payload([...$event, 'subtype' => 'channel_join'])];

        yield 'a post by an app' => [self::payload([...$event, 'bot_id' => 'B0APP'])];
        yield 'a post by an app with a blank bot id' => [self::payload([...$event, 'bot_id' => ''])];
        yield 'a post by this app itself' => [self::payload([...$event, 'user' => self::BOT])];
        yield 'a post by nobody' => [self::payload(self::without($event, 'user'))];
        yield 'a post by an empty user' => [self::payload([...$event, 'user' => ''])];

        yield 'a message from no channel' => [self::payload(self::without($event, 'channel'))];
        yield 'a message from a blank channel' => [self::payload([...$event, 'channel' => ''])];
        yield 'a message with no ts at all' => [self::payload(self::without($event, 'ts'))];
        yield 'a message with a blank ts' => [self::payload([...$event, 'ts' => ''])];
    }

    /**
     * @param array<array-key, mixed> $event
     *
     * @return SlackMessage|null what that event turned out to be
     */
    private static function read(array $event): ?SlackMessage
    {
        return SlackMessage::from(self::payload($event), self::BOT);
    }

    /**
     * @param array<array-key, mixed> $event
     *
     * @return array<array-key, mixed> the envelope payload carrying it
     */
    private static function payload(array $event): array
    {
        return ['type' => 'event_callback', 'event' => $event];
    }

    /**
     * @param array<array-key, mixed> $overrides
     *
     * @return array<array-key, mixed> a mention from a person, with whatever the case changed
     */
    private static function event(array $overrides = []): array
    {
        return [
            'type' => 'app_mention',
            'user' => self::HUMAN,
            'channel' => 'C0CHANNEL',
            'text' => 'hello',
            ...$overrides,
        ];
    }

    /**
     * @param array<array-key, mixed> $event
     *
     * @return array<array-key, mixed> the same event without that key, which is how Slack omits one
     */
    private static function without(array $event, string $key): array
    {
        unset($event[$key]);

        return $event;
    }
}
