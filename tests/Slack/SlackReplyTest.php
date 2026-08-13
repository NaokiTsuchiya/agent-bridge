<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use NaokiTsuchiya\AgentBridge\Slack\SlackReply;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The answer as one `chat.postMessage`, driven on its own.
 *
 * It is normally reached as {@see \NaokiTsuchiya\AgentBridge\Slack\SlackStreamingReply}'s fallback,
 * and everything about that hand-over belongs to {@see SlackStreamingReplyTest}. What is here is
 * what the fallback cannot produce: it is only ever handed a fragment it already has, so a reply
 * that stayed empty — and the second close that must not post the same answer again — have to be
 * put to this class directly.
 *
 * @internal
 */
final class SlackReplyTest extends TestCase
{
    /** The thread every case answers in. */
    private const string NATIVE_ID = '1700000001.123456';

    /** Where that thread lives. */
    private const string CHANNEL = 'C0CHANNEL';

    /** A turn that produced no text is not an empty message; Slack refuses one anyway. */
    #[Test]
    public function postsNothingWhenThereWasNothingToSay(): void
    {
        [$reply, $api] = self::reply();

        $reply->close();

        self::assertSame([], $api->calls);
    }

    /**
     * The answer is emptied as it is sent, so a second close cannot post it a second time.
     *
     * A reply that arrives twice is worse than one that arrives late: the reader has no way to tell
     * which of the two messages the turn actually ended with.
     */
    #[Test]
    public function postsTheAnswerOnlyOnceWhenClosedTwice(): void
    {
        [$reply, $api] = self::reply();

        $reply->append('the answer');
        $reply->close();
        $reply->close();

        self::assertSame([SlackReply::POST_MESSAGE], $api->methods());
        self::assertSame('the answer', $api->argumentsOf(SlackReply::POST_MESSAGE)[0]['text'] ?? '');
    }

    /**
     * A post Slack will not carry out is said out loud rather than thrown.
     *
     * This is the end of the line — the stream has already been given up on by the time the message
     * is tried — so throwing here would take the turn down over an answer that is only lost.
     */
    #[Test]
    public function saysSoWhenTheReplyCouldNotBePosted(): void
    {
        [$reply, $api, $logger] = self::reply();
        $api->refuse(SlackReply::POST_MESSAGE, 'msg_too_long');

        $reply->append('the answer');
        $reply->close();

        self::assertSame([SlackReply::POST_MESSAGE], $api->methods(), 'The refused post was tried again.');
        self::assertContains('could not post the reply to ' . self::NATIVE_ID . ': msg_too_long', $logger->lines);
    }

    /**
     * @return array{SlackReply, FakeSlackApiClient, RecordingLogger} a reply of a thread that has
     *                                                               been heard from
     */
    private static function reply(): array
    {
        $api = new FakeSlackApiClient();
        $logger = new RecordingLogger();

        return [new SlackReply($api, self::CHANNEL, self::NATIVE_ID, $logger), $api, $logger];
    }
}
