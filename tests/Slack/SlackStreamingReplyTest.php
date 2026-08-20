<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function implode;
use function mb_strlen;
use function str_contains;
use function str_repeat;

/**
 * The reply as Slack's streaming methods see it.
 *
 * Everything here drives the front end's own stream against a fake Web API, because the order the
 * three methods are called in, what goes in each call, and what happens when one of them is refused
 * are the whole of this change — and none of it can be seen from the text that comes out at the end.
 *
 * @mago-expect lint:too-many-methods
 */
final class SlackStreamingReplyTest extends TestCase
{
    /** The thread every case answers in. */
    private const string NATIVE_ID = '1700000001.123456';

    /** Where that thread lives. */
    private const string CHANNEL = 'C0CHANNEL';

    /** Long enough to be past the default throttle window. */
    private const float PAST_THE_WINDOW = 1.0;

    /** The first fragment opens a stream, the next ones add to it, the end stops it. */
    #[Test]
    public function startsThenAddsThenStops(): void
    {
        [$reply, $api, $clock] = self::reply();

        $clock->advance(self::PAST_THE_WINDOW);
        $reply->append('one ');
        $clock->advance(self::PAST_THE_WINDOW);
        $reply->append('two ');
        $clock->advance(self::PAST_THE_WINDOW);
        $reply->append('three');
        $reply->close();

        self::assertSame(
            [
                SlackStream::START,
                SlackStream::APPEND,
                SlackStream::APPEND,
                SlackStream::STOP,
            ],
            $api->methods(),
        );
    }

    /**
     * Each call carries the fragment and not the answer so far.
     *
     * Sending the whole reply every time is the mistake this front end is most likely to make, and
     * the reader would never see it: Slack shows the same words either way.
     */
    #[Test]
    public function sendsTheDifferenceAndNotTheWholeReply(): void
    {
        [$reply, $api, $clock] = self::reply();

        $clock->advance(self::PAST_THE_WINDOW);
        $reply->append('one ');
        $clock->advance(self::PAST_THE_WINDOW);
        $reply->append('two ');
        $clock->advance(self::PAST_THE_WINDOW);
        $reply->append('three');
        $reply->close();

        $sent = SentCalls::texts($api);

        self::assertSame('one two three', implode('', $sent));
        self::assertSame('one ', $sent[0] ?? '');
        self::assertFalse(str_contains($sent[1] ?? '', 'one'), 'The second call repeated the first.');
    }

    /** Fragments inside the window are collected rather than sent one call each. */
    #[Test]
    public function collectsWhatArrivesInsideTheWindow(): void
    {
        [$reply, $api, $clock] = self::reply();

        $reply->append('one ');
        $clock->advance(0.599);
        $reply->append('two ');

        self::assertSame([], $api->calls, 'A fragment inside the window went out on its own.');

        $clock->advance(0.001);
        $reply->append('three');

        self::assertSame([SlackStream::START], $api->methods());
        self::assertSame('one two three', implode('', SentCalls::texts($api)), 'The window did not send all of it.');
    }

    /**
     * A turn shorter than the window still says everything it had to say.
     *
     * The clock does not move at all here, which is the shape of a turn that answered in less time
     * than the throttle: everything is still collected when the turn ends, and an implementation
     * that only stopped the stream would drop the whole answer.
     */
    #[Test]
    public function saysEverythingWhenTheTurnIsShorterThanTheWindow(): void
    {
        [$reply, $api] = self::reply();

        $reply->append('one ');
        $reply->append('two ');
        $reply->append('three');
        $reply->close();

        self::assertSame([SlackStream::START, SlackStream::STOP], $api->methods());
        self::assertSame('one two three', implode('', SentCalls::texts($api)));
    }

    /** What arrived after the last send goes out before the stream is stopped. */
    #[Test]
    public function sendsWhatIsLeftBeforeStopping(): void
    {
        [$reply, $api, $clock] = self::reply();

        $clock->advance(self::PAST_THE_WINDOW);
        $reply->append('the answer');
        $reply->append(' and the rest');
        $reply->append(' of it');
        $reply->close();

        self::assertSame([SlackStream::START, SlackStream::APPEND, SlackStream::STOP], $api->methods());
        self::assertSame(' and the rest of it', SentCalls::texts($api)[1] ?? '');
    }

    /** A turn that produced no text is not a message worth opening, let alone stopping. */
    #[Test]
    public function saysNothingWhenThereWasNothingToSay(): void
    {
        [$reply, $api] = self::reply();

        $reply->close();

        self::assertSame([], $api->calls);
    }

    /**
     * A fragment that is only whitespace is a fragment, and goes out as it arrived.
     *
     * Nothing to say and nothing but blank space are two different things: the blank space is the
     * break between two paragraphs of the answer, and an emptiness test that trimmed before
     * deciding — or a send that trimmed before going out — would quietly take it away.
     */
    #[Test]
    public function sendsADifferenceThatIsOnlyWhitespace(): void
    {
        [$reply, $api] = self::reply();

        $reply->append(' ');
        $reply->append("\n");
        $reply->close();

        self::assertSame([SlackStream::START, SlackStream::STOP], $api->methods());
        self::assertSame([" \n"], SentCalls::texts($api));
    }

    /** Closing twice does not end the same reply twice. */
    #[Test]
    public function endsTheReplyOnce(): void
    {
        [$reply, $api] = self::reply();

        $reply->append('hello');
        $reply->close();
        $reply->close();

        self::assertSame([SlackStream::START, SlackStream::STOP], $api->methods());
    }

    /** The pace is the settings', not this class's. */
    #[Test]
    public function takesItsPaceFromTheSettings(): void
    {
        [$reply, $api, $clock] = self::reply(new StreamingSettings(throttleMilliseconds: 100));

        $reply->append('one ');
        $clock->advance(0.1);
        $reply->append('two');

        self::assertSame([SlackStream::START], $api->methods(), 'A shorter window did not send sooner.');
    }

    /** The default pace stays inside the tier `chat.appendStream` is rated at. */
    #[Test]
    public function defaultsToAWindowSlackAllows(): void
    {
        self::assertGreaterThanOrEqual(600, new StreamingSettings()->throttleMilliseconds);
    }

    /** A send at the limit is one send; the split is for what does not fit. */
    #[Test]
    public function sendsWhatFitsInOneCall(): void
    {
        [$reply, $api] = self::reply();

        $reply->append(str_repeat('a', times: 12_000));
        $reply->close();

        self::assertSame([SlackStream::START, SlackStream::STOP], $api->methods());
        self::assertSame([12_000], array_map(mb_strlen(...), SentCalls::texts($api)));
    }

    /** What is longer than one call is split, and nothing of it is lost. */
    #[Test]
    public function splitsWhatIsTooLongForOneCall(): void
    {
        [$reply, $api] = self::reply();
        $answer = str_repeat('a', times: 12_001);

        $reply->append($answer);
        $reply->close();

        $sent = SentCalls::texts($api);

        self::assertSame([12_000, 1], array_map(mb_strlen(...), $sent));
        self::assertSame($answer, implode('', $sent));
        self::assertSame([SlackStream::START, SlackStream::APPEND, SlackStream::STOP], $api->methods());
    }

    /** The split keeps going: the second call is not allowed to carry the whole remainder. */
    #[Test]
    public function keepsSplittingUntilEveryCallFits(): void
    {
        [$reply, $api] = self::reply();
        $answer = str_repeat('a', times: 24_001);

        $reply->append($answer);
        $reply->close();

        $sent = SentCalls::texts($api);

        self::assertSame([12_000, 12_000, 1], array_map(mb_strlen(...), $sent));
        self::assertSame($answer, implode('', $sent));
    }

    /** A tool call goes out as a task update rather than as part of the answer. */
    #[Test]
    public function announcesAToolCallAsATaskUpdate(): void
    {
        [$reply, $api] = self::reply();

        $reply->append("looking\n> Grep\n");
        $reply->append(' found it');
        $reply->close();

        self::assertSame(['Grep'], SentCalls::taskTitles($api));

        $answer = implode('', SentCalls::texts($api));
        self::assertFalse(str_contains($answer, '>'), 'The announcement stayed in the answer as well.');
        self::assertTrue(str_contains($answer, 'looking'));
        self::assertTrue(str_contains($answer, 'found it'));
    }

    /** A task update as long as Slack allows is sent as it is. */
    #[Test]
    public function sendsAnAnnouncementSlackWillTake(): void
    {
        [$reply, $api] = self::reply();

        $reply->append("\n> " . str_repeat('t', times: 256) . "\n");
        $reply->close();

        self::assertSame([256], array_map(mb_strlen(...), SentCalls::taskTitles($api)));
    }

    /** A longer one is cut down to it, because Slack refuses the whole call otherwise. */
    #[Test]
    public function cutsAnAnnouncementDownToWhatSlackTakes(): void
    {
        [$reply, $api] = self::reply();

        $reply->append("\n> " . str_repeat('t', times: 257) . "\n");
        $reply->close();

        self::assertSame([256], array_map(mb_strlen(...), SentCalls::taskTitles($api)));
    }

    /** A quote mark in the middle of a sentence is a sentence. */
    #[Test]
    public function leavesTheAnswersOwnQuotingAlone(): void
    {
        [$reply, $api] = self::reply();

        $reply->append('use a > b to compare them');
        $reply->close();

        self::assertSame([], SentCalls::taskTitles($api));
        self::assertSame('use a > b to compare them', implode('', SentCalls::texts($api)));
    }

    /** A workspace that will not open a stream still gets its answer, as one message. */
    #[Test]
    public function fallsBackToOneMessageWhenTheStreamCannotStart(): void
    {
        [$reply, $api, $clock] = self::reply();
        $api->refuse(SlackStream::START, 'method_not_supported');

        $reply->append('the ');
        $clock->advance(self::PAST_THE_WINDOW);
        $reply->append('answer');
        $reply->close();

        self::assertSame(
            [SlackStream::START, SlackReply::POST_MESSAGE],
            $api->methods(),
            'The stream was carried on with after it could not be started.',
        );
        self::assertSame('the answer', $api->argumentsOf(SlackReply::POST_MESSAGE)[0]['text'] ?? '');
    }

    /**
     * Once the answer is going out as one message, what arrives after it goes the same way.
     *
     * The stream was refused at its start, and it is not offered anything again: retrying it would
     * put half the answer in a message and half in a stream. This is a different moment from
     * {@see fallsBackToOneMessageWhenTheStreamCannotStart()}, where nothing arrives after the
     * refusal — the clock is moved before the first fragment so that the fallback is settled while
     * the turn is still going.
     */
    #[Test]
    public function keepsAddingToTheMessageAfterFallingBack(): void
    {
        [$reply, $api, $clock] = self::reply();
        $api->refuse(SlackStream::START, 'method_not_supported');

        $clock->advance(self::PAST_THE_WINDOW);
        $reply->append('the ');
        $clock->advance(self::PAST_THE_WINDOW);
        $reply->append('answer');
        $reply->close();

        self::assertSame(
            [SlackStream::START, SlackReply::POST_MESSAGE],
            $api->methods(),
            'The stream was offered the fragment that arrived after the fallback.',
        );
        self::assertSame('the answer', $api->argumentsOf(SlackReply::POST_MESSAGE)[0]['text'] ?? '');
    }

    /** The same when the stream is opened but Slack names no message to add to. */
    #[Test]
    public function fallsBackWhenNoStreamCameBack(): void
    {
        [$reply, $api] = self::reply();
        $api->script(SlackStream::START, new SlackApiResult(ok: true));

        $reply->append('the answer');
        $reply->close();

        self::assertSame([SlackStream::START, SlackReply::POST_MESSAGE], $api->methods());
        self::assertSame('the answer', $api->argumentsOf(SlackReply::POST_MESSAGE)[0]['text'] ?? '');
    }

    /**
     * A fragment Slack would not take does not end the stream, and does not post the answer twice.
     *
     * The message is already in the thread by then: falling back here would say everything a second
     * time, in a second message, which is worse than the fragment being missing.
     */
    #[Test]
    public function keepsTheStreamWhenAFragmentIsRefused(): void
    {
        [$reply, $api, $clock] = self::reply();
        $api->refuse(SlackStream::APPEND, 'msg_too_long');

        $clock->advance(self::PAST_THE_WINDOW);
        $reply->append('the ');
        $clock->advance(self::PAST_THE_WINDOW);
        $reply->append('answer');
        $clock->advance(self::PAST_THE_WINDOW);
        $reply->append(' again');
        $reply->close();

        self::assertSame(
            [
                SlackStream::START,
                SlackStream::APPEND,
                SlackStream::APPEND,
                SlackStream::STOP,
            ],
            $api->methods(),
        );
    }

    /** Nor does a refused ending. */
    #[Test]
    public function survivesARefusedEnding(): void
    {
        [$reply, $api] = self::reply();
        $api->refuse(SlackStream::STOP, 'message_not_found');

        $reply->append('the answer');
        $reply->close();

        self::assertSame([SlackStream::START, SlackStream::STOP], $api->methods());
    }

    /**
     * A rate limit is waited out and the call made again; the stream carries on either way.
     *
     * This is the one case where the client in front of the fake is the real
     * {@see RetryingSlackApiClient}, because the waiting is its own and the stream is only supposed
     * to see the answer to the retry.
     */
    #[Test]
    public function waitsOutARateLimitWithoutLosingTheStream(): void
    {
        $api = new FakeSlackApiClient();
        $sleeper = new RecordingSleeper();
        $settings = new StreamingSettings();
        $clock = new FixedClock();
        $api->script(SlackStream::APPEND, new SlackApiResult(ok: false, error: 'ratelimited', retryAfter: 2.0));
        $reply = self::replyOver(new RetryingSlackApiClient($api, $sleeper, $settings), $settings, $clock);

        $clock->advance(self::PAST_THE_WINDOW);
        $reply->append('the ');
        $clock->advance(self::PAST_THE_WINDOW);
        $reply->append('answer');
        $reply->close();

        self::assertSame([2.0], $sleeper->delays);
        self::assertSame(
            [
                SlackStream::START,
                SlackStream::APPEND,
                SlackStream::APPEND,
                SlackStream::STOP,
            ],
            $api->methods(),
            'The rate limited call was not made again, or the stream did not survive it.',
        );
        // The same fragment a second time, not the answer so far: a retry is the call again.
        self::assertSame(['the ', 'answer', 'answer'], SentCalls::texts($api));
    }

    /** Every streamed reply is a thread reply; a `ts` of the thread is on the call that opens it. */
    #[Test]
    public function opensTheStreamInTheThread(): void
    {
        [$reply, $api] = self::reply();

        $reply->append('the answer');
        $reply->close();

        self::assertSame(self::NATIVE_ID, $api->argumentsOf(SlackStream::START)[0]['thread_ts'] ?? null);
        self::assertSame(self::CHANNEL, $api->argumentsOf(SlackStream::START)[0]['channel'] ?? null);
        self::assertSame(FakeSlackApiClient::STREAM_TS, $api->argumentsOf(SlackStream::STOP)[0]['ts'] ?? null);
    }

    /** A thread nobody has been heard from has nowhere to answer, and says so rather than throwing. */
    #[Test]
    public function saysNothingAboutAThreadItNeverHeardFrom(): void
    {
        $api = new FakeSlackApiClient();
        $logger = new RecordingLogger();
        $settings = new StreamingSettings();
        $reply = new SlackStreamingReply(
            new SlackStream($api, null, self::NATIVE_ID, $logger, $settings),
            new Throttle(new FixedClock(), $settings->throttleMilliseconds),
            new SlackReply($api, null, self::NATIVE_ID, $logger),
        );

        $reply->append('an answer nobody asked for');
        $reply->close();

        self::assertSame([], $api->calls);
    }

    /**
     * @return array{SlackStreamingReply, FakeSlackApiClient, FixedClock} a reply of a thread that
     *                                                                   has been heard from
     */
    private static function reply(?StreamingSettings $settings = null): array
    {
        $api = new FakeSlackApiClient();
        $clock = new FixedClock();

        return [self::replyOver($api, $settings ?? new StreamingSettings(), $clock), $api, $clock];
    }

    /** @param SlackApiClient $api what the reply talks to, which may be a decorator over the fake */
    private static function replyOver(
        SlackApiClient $api,
        StreamingSettings $settings,
        ClockInterface $clock,
    ): SlackStreamingReply {
        $logger = new RecordingLogger();

        return new SlackStreamingReply(
            new SlackStream($api, self::CHANNEL, self::NATIVE_ID, $logger, $settings),
            new Throttle($clock, $settings->throttleMilliseconds),
            new SlackReply($api, self::CHANNEL, self::NATIVE_ID, $logger),
        );
    }
}
