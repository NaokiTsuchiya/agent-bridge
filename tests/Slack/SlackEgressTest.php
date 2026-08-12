<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use Be\Framework\BecomingInterface;
use Be\Framework\Module\BeModule;
use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\AgentBridge;
use NaokiTsuchiya\AgentBridge\Chat\ChatEgress;
use NaokiTsuchiya\AgentBridge\Chat\StreamHandle;
use NaokiTsuchiya\AgentBridge\Event\AgentEvent;
use NaokiTsuchiya\AgentBridge\Event\TextDelta;
use NaokiTsuchiya\AgentBridge\Event\ToolCompleted;
use NaokiTsuchiya\AgentBridge\Event\ToolStarted;
use NaokiTsuchiya\AgentBridge\Event\TurnCompleted;
use NaokiTsuchiya\AgentBridge\Pipeline\CompletedTurn;
use NaokiTsuchiya\AgentBridge\Pipeline\IncomingMessage;
use NaokiTsuchiya\AgentBridge\Slack\SlackEgress;
use NaokiTsuchiya\AgentBridge\Slack\SlackMessage;
use NaokiTsuchiya\AgentBridge\Slack\SlackReply;
use NaokiTsuchiya\AgentBridge\Slack\SlackStream;
use NaokiTsuchiya\AgentBridge\Slack\StreamingSettings;
use NaokiTsuchiya\AgentBridge\Slack\ThreadChannels;
use NaokiTsuchiya\AgentBridge\Tests\Pipeline\PipelineModule;
use NaokiTsuchiya\AgentBridge\Tests\Pipeline\StubAgentRunner;
use NaokiTsuchiya\AgentBridge\Tests\Support\Coro;
use NaokiTsuchiya\AgentBridge\Tests\Support\GitRepository;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Throwable;

use function implode;
use function str_contains;

/**
 * What a turn looks like by the time it reaches a workspace.
 *
 * Two ways in on purpose. The cases about a whole turn go through the pipeline itself — the status,
 * the streaming and the tool announcements are only worth anything in the order and the shape the
 * pipeline produces them in — while the cases about a thread nobody has heard from, or a turn that
 * said nothing, drive the front end directly, because the pipeline has no way to produce them.
 *
 * The reply's own behaviour — the throttle, the splitting, what happens when a fragment is refused
 * — belongs to {@see SlackStreamingReplyTest}. The clock here never moves, which is what a turn
 * finishing inside the throttle window looks like: everything goes out when the turn ends.
 *
 * @mago-expect lint:too-many-methods
 */
final class SlackEgressTest extends TestCase
{
    /** The thread every case uses; the second vector of docs/poc-design.md. */
    private const string NATIVE_ID = '1700000001.123456';

    /** Where that thread lives. */
    private const string CHANNEL = 'C0CHANNEL';

    /** The method a status is shown with, when the workspace lets it be. */
    private const string SET_STATUS = 'assistant.threads.setStatus';

    /** The repository the worktrees of a case are cut from, when the case goes through the chain. */
    private string $repository = '';

    /** {@inheritDoc} */
    #[Override]
    protected function tearDown(): void
    {
        if ($this->repository === '') {
            return;
        }

        GitRepository::remove($this->repository);
        $this->repository = '';
    }

    /** The front end is one of the three ports. */
    #[Test]
    public function isTheEgressPort(): void
    {
        [$egress] = $this->frontEnd();

        self::assertInstanceOf(ChatEgress::class, $egress);
        self::assertInstanceOf(StreamHandle::class, $egress->open(self::thread()));
    }

    /** A turn says it has been taken on before it has anything to say. */
    #[Test]
    public function showsAStatusAsSoonAsTheTurnIsAccepted(): void
    {
        [$egress, $api] = $this->frontEnd();

        $egress->status(self::thread(), 'Working on it.');

        self::assertSame([self::SET_STATUS], $api->methods());
        self::assertSame(
            [
                'channel_id' => self::CHANNEL,
                'thread_ts' => self::NATIVE_ID,
                'status' => 'Working on it.',
            ],
            $api->argumentsOf(self::SET_STATUS)[0] ?? [],
        );
    }

    /**
     * Where the status method does not work, the status is a message instead.
     *
     * Slack documents `assistant.threads.setStatus` for assistant threads and says nothing about an
     * ordinary channel's thread, so the answer is believed rather than the documentation guessed at.
     */
    #[Test]
    public function fallsBackToAMessageWhenTheStatusIsRefused(): void
    {
        [$egress, $api] = $this->frontEnd();
        $api->refuse(self::SET_STATUS, 'not_allowed_token_type');

        $egress->status(self::thread(), 'Working on it.');

        self::assertSame([self::SET_STATUS, SlackReply::POST_MESSAGE], $api->methods());
        self::assertSame(
            [
                'channel' => self::CHANNEL,
                'thread_ts' => self::NATIVE_ID,
                'text' => 'Working on it.',
            ],
            $api->argumentsOf(SlackReply::POST_MESSAGE)[0] ?? [],
        );
    }

    /** A turn whose thread was never heard from has nowhere to go, and says so rather than throwing. */
    #[Test]
    public function saysNothingAboutAThreadItNeverHeardFrom(): void
    {
        [$egress, $api] = $this->frontEndWithNoChannels();

        $egress->status(self::thread(), 'Working on it.');
        $reply = $egress->open(self::thread());
        $reply->append('an answer nobody asked for');
        $reply->close();

        self::assertSame([], $api->calls);
    }

    /** A turn that produced no text is not an empty message. */
    #[Test]
    public function postsNothingWhenThereWasNothingToSay(): void
    {
        [$egress, $api] = $this->frontEnd();

        $egress->open(self::thread())->close();

        self::assertSame([], $api->calls);
    }

    /** Closing twice does not end the same reply twice. */
    #[Test]
    public function endsAnAnswerOnce(): void
    {
        [$egress, $api] = $this->frontEnd();
        $reply = $egress->open(self::thread());

        $reply->append('hello');
        $reply->close();
        $reply->close();

        self::assertSame([SlackStream::START, SlackStream::STOP], $api->methods());
    }

    /**
     * The whole turn, as the pipeline produces it: a status, then the answer as a streamed reply.
     *
     * @throws Throwable
     */
    #[Test]
    public function streamsATurnIntoAThreadedReply(): void
    {
        $api = $this->answer([
            new TextDelta('the '),
            new TextDelta('answer'),
            new TurnCompleted(success: true, sessionId: 'session'),
        ]);

        self::assertSame([self::SET_STATUS, SlackStream::START, SlackStream::STOP], $api->methods());
        self::assertSame('the answer', implode('', SentCalls::texts($api)));
    }

    /**
     * A workspace that will not open a stream still gets the answer, and the turn still completes.
     *
     * The turn reaching {@see CompletedTurn} is the assertion in {@see answer()}: a fallback that
     * threw would end the turn there, and the reader would be left with a status and nothing else.
     *
     * @throws Throwable
     */
    #[Test]
    public function answersWithOneMessageWhenTheStreamCannotStart(): void
    {
        $api = $this->answer([
            new TextDelta('the '),
            new TextDelta('answer'),
            new TurnCompleted(success: true, sessionId: 'session'),
        ], unstreamable: 'method_not_supported');

        self::assertSame([self::SET_STATUS, SlackStream::START, SlackReply::POST_MESSAGE], $api->methods());
        self::assertSame('the answer', $api->argumentsOf(SlackReply::POST_MESSAGE)[0]['text'] ?? '');
    }

    /**
     * Every answer is a thread reply. An answer in the channel next to the question is the one
     * mistake that cannot be taken back.
     *
     * @throws Throwable
     */
    #[Test]
    public function repliesInTheThreadAndNowhereElse(): void
    {
        $api = $this->answer(
            [
                new TextDelta('the answer'),
                new TurnCompleted(success: true, sessionId: 'session'),
            ],
            refusal: 'not_allowed_token_type',
            unstreamable: 'method_not_supported',
        );

        $addressed = [
            ...$api->argumentsOf(SlackStream::START),
            ...$api->argumentsOf(SlackReply::POST_MESSAGE),
        ];

        self::assertNotSame([], $addressed);

        foreach ($addressed as $arguments) {
            self::assertArrayHasKey('thread_ts', $arguments);
            self::assertSame(self::NATIVE_ID, $arguments['thread_ts'] ?? null);
        }
    }

    /**
     * A tool call goes out as a task update, which Slack shows apart from the answer.
     *
     * Both ends of the call are there, and neither is in the reply text — which is the whole of
     * "told apart from the body".
     *
     * @throws Throwable
     */
    #[Test]
    public function tellsToolCallsApartFromTheAnswer(): void
    {
        $api = $this->answer([
            new TextDelta('looking'),
            new ToolStarted('Grep', 'toolu_1'),
            new ToolCompleted('toolu_1', success: true),
            new TextDelta(' found it'),
            new TurnCompleted(success: true, sessionId: 'session'),
        ]);

        self::assertSame(['Grep', 'toolu_1 done'], SentCalls::taskTitles($api));

        $streamed = implode('', SentCalls::texts($api));
        self::assertTrue(str_contains($streamed, 'looking'));
        self::assertTrue(str_contains($streamed, 'found it'));
        self::assertFalse(str_contains($streamed, '>'), 'An announcement stayed in the answer too.');
    }

    /**
     * A tool call that failed says so, in the same form.
     *
     * @throws Throwable
     */
    #[Test]
    public function saysWhenAToolCallFailed(): void
    {
        $api = $this->answer([
            new ToolStarted('Bash', 'toolu_9'),
            new ToolCompleted('toolu_9', success: false),
            new TextDelta('that did not work'),
            new TurnCompleted(success: true, sessionId: 'session'),
        ]);

        self::assertSame(['Bash', 'toolu_9 failed'], SentCalls::taskTitles($api));
    }

    /**
     * Runs one turn through the real pipeline into a real Slack front end.
     *
     * @param list<AgentEvent> $events       what the execution layer answers with
     * @param string|null      $refusal      what the workspace says about showing a status, when it
     *                                       will not show one
     * @param string|null      $unstreamable what it says about opening a stream, when it will not
     *                                       open one
     *
     * @return FakeSlackApiClient everything that reached the workspace
     *
     * @throws Throwable
     */
    private function answer(array $events, ?string $refusal = null, ?string $unstreamable = null): FakeSlackApiClient
    {
        $this->repository = GitRepository::make('slack-egress-repo');

        [$egress, $api] = $this->frontEnd();

        if ($refusal !== null) {
            $api->refuse(self::SET_STATUS, $refusal);
        }

        if ($unstreamable !== null) {
            $api->refuse(SlackStream::START, $unstreamable);
        }

        $becoming = new Injector(
            new BeModule(
                AgentBridge::SEMANTIC_NAMESPACE,
                new PipelineModule(
                    PipelineModule::worktreesOf($this->repository),
                    new StubAgentRunner($events),
                    $egress,
                ),
            ),
        )->getInstance(BecomingInterface::class);

        $completed = null;
        Coro::run(static function () use ($becoming, &$completed): void {
            $completed = $becoming(new IncomingMessage(SlackMessage::PLATFORM, self::NATIVE_ID, 'hello'));
        });

        self::assertInstanceOf(CompletedTurn::class, $completed);

        return $api;
    }

    /**
     * @return array{SlackEgress, FakeSlackApiClient} the front end of a thread that has been heard
     *                                                from, and what it talks to
     */
    private function frontEnd(): array
    {
        $channels = new ThreadChannels();
        $channels->remember(self::NATIVE_ID, self::CHANNEL);

        return self::frontEndOf($channels);
    }

    /**
     * @return array{SlackEgress, FakeSlackApiClient} the front end of a process that has heard from
     *                                                nobody, which is what a restart leaves behind
     */
    private function frontEndWithNoChannels(): array
    {
        return self::frontEndOf(new ThreadChannels());
    }

    /** @return array{SlackEgress, FakeSlackApiClient} */
    private static function frontEndOf(ThreadChannels $channels): array
    {
        $api = new FakeSlackApiClient();
        $egress = new SlackEgress($api, $channels, new RecordingLogger(), new StreamingSettings(), new FixedClock());

        return [$egress, $api];
    }

    /** @return ThreadId the thread every case answers in */
    private static function thread(): ThreadId
    {
        try {
            return new ThreadId(SlackMessage::PLATFORM . ':' . self::NATIVE_ID);
        } catch (InvalidArgumentException $impossible) {
            // The value is a constant of this class, so this cannot happen; it is caught rather
            // than declared so that every case above does not have to carry the tag.
            self::fail($impossible->getMessage());
        }
    }
}
