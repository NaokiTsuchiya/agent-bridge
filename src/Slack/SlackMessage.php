<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use NaokiTsuchiya\AgentBridge\Thread\ThreadId;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_string;

/**
 * One Socket Mode payload, read as a message this app has to answer — or read as nothing.
 *
 * Every rule about what is worth answering lives in {@see from()}: what kind of event it is, whether
 * this app wrote it, and which thread it belongs to. It is a value object rather than a method on
 * the ingress because that is what lets the rules be exercised one payload at a time, without a
 * channel and without a coroutine.
 *
 * The complexity the linter counts here is the count of those rules, and splitting them across
 * classes would only make the list of what this front end ignores harder to read as a list.
 *
 * @mago-expect lint:cyclomatic-complexity
 *
 * @api
 */
final readonly class SlackMessage
{
    /** The name this front end goes by in a {@see ThreadId}. */
    public const string PLATFORM = 'slack';

    /**
     * The event types worth answering.
     *
     * `app_mention` is what a mention in a channel arrives as; `message` covers a direct message and
     * a reply typed in a thread the app is already in. Anything else — a reaction, somebody joining
     * — is dropped without a word, which is what "silently discard" in the issue asks for.
     *
     * @var list<string>
     */
    private const array ANSWERABLE = ['app_mention', 'message'];

    /**
     * @param string $channel  where the reply has to go; not part of the thread id
     * @param string $nativeId what Slack calls the thread, which is the second half of the thread id
     * @param string $text     what was said
     */
    private function __construct(
        public string $channel,
        public string $nativeId,
        public string $text,
    ) {}

    /**
     * Reads one payload, answering with null for everything this app does not reply to.
     *
     * **The thread id is the point.** A message posted in a thread carries `thread_ts`; the first
     * message of a thread has none, so its own `ts` is used — and every later reply carries that
     * same value as its `thread_ts`, which is what makes the whole exchange resolve to one thread,
     * one session and one worktree.
     *
     * @param array<array-key, mixed> $payload    an `event_callback` as Socket Mode delivered it
     * @param string                  $botUserId  this app's own user id, so its posts are skipped
     */
    public static function from(array $payload, string $botUserId): ?self
    {
        $event = self::text($payload, 'type') === 'event_callback' ? self::object($payload, 'event') : null;

        if ($event === null || !self::isAMessage($event) || !self::byAPerson($event, $botUserId)) {
            return null;
        }

        return self::of($event);
    }

    /**
     * Whether the event is a message rather than something that happened to one.
     *
     * A subtype means the latter: an edit, a deletion, a join notice, a post by an app. None of
     * them is somebody asking for an answer.
     *
     * @param array<array-key, mixed> $event
     *
     * @pure
     */
    private static function isAMessage(array $event): bool
    {
        return (
            in_array(self::text($event, 'type'), self::ANSWERABLE, strict: true) && !array_key_exists('subtype', $event)
        );
    }

    /**
     * Whether somebody other than this app wrote it.
     *
     * The two ways of recognising this app's own posts are both applied on purpose. `bot_id` is set
     * on anything an app posted, including this one; `user` catches the case where the post is
     * attributed to the app's user rather than to a bot — a message posted by another integration
     * acting as this user. The comparison is an identity: a person whose id merely starts the same
     * way is a person.
     *
     * @param array<array-key, mixed> $event
     *
     * @pure
     */
    private static function byAPerson(array $event, string $botUserId): bool
    {
        $user = self::text($event, 'user') ?? '';

        return !array_key_exists('bot_id', $event) && $user !== '' && $user !== $botUserId;
    }

    /**
     * @param array<array-key, mixed> $event
     *
     * @return self|null null when the event names no thread to answer in, or nowhere to answer
     */
    private static function of(array $event): ?self
    {
        $channel = self::text($event, 'channel') ?? '';
        $ts = self::text($event, 'ts') ?? '';

        if ($channel === '' || $ts === '') {
            return null;
        }

        $threadTs = self::text($event, 'thread_ts') ?? '';

        return new self($channel, $threadTs === '' ? $ts : $threadTs, self::text($event, 'text') ?? '');
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>|null
     *
     * @pure
     */
    private static function object(array $node, string $key): ?array
    {
        return is_array($node[$key] ?? null) ? $node[$key] : null;
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @pure
     */
    private static function text(array $node, string $key): ?string
    {
        return is_string($node[$key] ?? null) ? $node[$key] : null;
    }
}
