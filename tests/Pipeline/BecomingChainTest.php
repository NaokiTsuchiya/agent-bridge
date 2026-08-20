<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Pipeline;

use Be\Framework\BecomingInterface;
use Be\Framework\Module\BeModule;
use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\AgentBridge;
use NaokiTsuchiya\AgentBridge\Event\ClaudeCliEventParser;
use NaokiTsuchiya\AgentBridge\Event\TextDelta;
use NaokiTsuchiya\AgentBridge\Event\ToolCompleted;
use NaokiTsuchiya\AgentBridge\Event\TurnCompleted;
use NaokiTsuchiya\AgentBridge\Pipeline\CompletedTurn;
use NaokiTsuchiya\AgentBridge\Pipeline\FailedTurn;
use NaokiTsuchiya\AgentBridge\Pipeline\IncomingMessage;
use NaokiTsuchiya\AgentBridge\Pipeline\Turn;
use NaokiTsuchiya\AgentBridge\Runner\AgentRunner;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliCommand;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliSettings;
use NaokiTsuchiya\AgentBridge\Runner\LifecycleSettings;
use NaokiTsuchiya\AgentBridge\Runner\PersistentCliRunner;
use NaokiTsuchiya\AgentBridge\Runner\ProcessPool;
use NaokiTsuchiya\AgentBridge\Runner\ProcessRecipe;
use NaokiTsuchiya\AgentBridge\Runner\TurnLocks;
use NaokiTsuchiya\AgentBridge\Runner\WorktreeWorkingDirectory;
use NaokiTsuchiya\AgentBridge\Tests\Chat\RecordingChatEgress;
use NaokiTsuchiya\AgentBridge\Tests\Runner\FakeCliRecords;
use NaokiTsuchiya\AgentBridge\Tests\Support\ClaudeBinary;
use NaokiTsuchiya\AgentBridge\Tests\Support\Coro;
use NaokiTsuchiya\AgentBridge\Tests\Support\GitRepository;
use NaokiTsuchiya\AgentBridge\Tests\Support\Json;
use NaokiTsuchiya\AgentBridge\Tests\Support\TempDir;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Swoole\Runtime;
use Throwable;

use function array_filter;
use function array_values;
use function count;
use function file_put_contents;
use function glob;
use function is_dir;
use function json_encode;
use function putenv;
use function str_contains;
use function str_ends_with;

/**
 * The chain as an application drives it: one call to {@see BecomingInterface}, one answered turn.
 *
 * Every case builds a repository and a front end of its own and runs the fake CLI as the agent, so
 * that what is asserted is the pipeline rather than a mock of it: the worktree is a real one, the
 * turn is a real child process, and the reply is what that process wrote.
 *
 * @mago-expect lint:too-many-methods
 */
final class BecomingChainTest extends TestCase
{
    /** The thread every case that does not care about the id uses. */
    private const string PLATFORM = 'slack';

    /** Paired with {@see self::PLATFORM} it is the second vector of docs/poc-design.md. */
    private const string NATIVE_ID = '1700000001.123456';

    /** Swoole's hook flags as they were before this class ran. */
    private static int $hookFlags = 0;

    /** Where the fake keeps this case's sessions and recordings. */
    private string $home = '';

    /** The repository the worktrees are cut from. */
    private string $repository = '';

    /** @var list<RecordingChatEgress> where the turns went out to, one per chain this case built */
    private array $egresses = [];

    /**
     * Building a runner turns on Swoole's hooks process-wide, which is not this class's to leave
     * on: a later case that starts a process outside a coroutine dies once they are.
     */
    #[Override]
    public static function setUpBeforeClass(): void
    {
        self::$hookFlags = Runtime::getHookFlags();
    }

    /** {@inheritDoc} */
    #[Override]
    public static function tearDownAfterClass(): void
    {
        Runtime::setHookFlags(self::$hookFlags);
    }

    /** {@inheritDoc} */
    #[Override]
    protected function setUp(): void
    {
        $this->home = TempDir::make('pipeline-home');
        $this->repository = GitRepository::make('pipeline-repo');

        putenv("FAKE_CLAUDE_HOME={$this->home}");
    }

    /** {@inheritDoc} */
    #[Override]
    protected function tearDown(): void
    {
        putenv('FAKE_CLAUDE_HOME');
        putenv('FAKE_CLAUDE_SCENARIO');
        TempDir::remove($this->home);
        GitRepository::remove($this->repository);
    }

    /**
     * The whole point: handing over a raw message gives back a finished turn, with nothing in
     * between written by hand.
     *
     * @throws Throwable
     */
    #[Test]
    public function reachesCompletedTurnInOneCall(): void
    {
        $completed = $this->answer('what is the weather');

        self::assertInstanceOf(CompletedTurn::class, $completed);
        self::assertStringContainsString('fake reply to: what is the weather', $completed->reply);
        self::assertSame(self::PLATFORM . ':' . self::NATIVE_ID, $completed->workspace->thread->value);
    }

    /**
     * The session is the one #3 derives, which is how a turn finds what the thread said before.
     *
     * @throws Throwable
     */
    #[Test]
    public function carriesTheDerivedSessionId(): void
    {
        $completed = $this->answer('hello');

        self::assertSame('959a94a6-5395-5d07-bc71-0a0c7d800476', $completed->workspace->sessionId);
    }

    /**
     * The worktree exists before the turn runs, and the turn runs in it.
     *
     * The recorded working directory is what makes this more than a path comparison: the child was
     * started there, so the directory was real by the time the agent was asked anything.
     *
     * @throws Throwable
     */
    #[Test]
    public function runsTheTurnInsideTheWorktree(): void
    {
        $completed = $this->answer('hello');

        self::assertSame("{$this->repository}/.worktrees/slack-1700000001-123456", $completed->workspace->worktree);
        self::assertDirectoryExists($completed->workspace->worktree);
        self::assertSame($completed->workspace->worktree, Json::text($this->records()->starts()[0] ?? [], 'cwd'));
    }

    /**
     * A native id may carry colons of its own; only the first one separates the two parts.
     *
     * @throws Throwable
     */
    #[Test]
    public function acceptsANativeIdWithMoreColons(): void
    {
        $completed = $this->answer('hello', nativeId: 'C123:456');

        self::assertSame('C123:456', $completed->workspace->thread->nativeId);
        self::assertTrue(str_ends_with($completed->workspace->worktree, '/.worktrees/slack-C123-456'));
        self::assertDirectoryExists($completed->workspace->worktree);
    }

    /**
     * `..` is refused in the native id and accepted in the platform, because a platform cannot
     * carry a slash and therefore cannot climb out of anything.
     *
     * @throws Throwable
     */
    #[Test]
    public function acceptsDotDotOnThePlatformSide(): void
    {
        $completed = $this->answer('hello', platform: 'a..b', nativeId: 'x');

        self::assertTrue(str_ends_with($completed->workspace->worktree, '/.worktrees/a--b-x'));
        self::assertDirectoryExists($completed->workspace->worktree);
    }

    /**
     * A message that does not name a valid thread never becomes a turn.
     *
     * @throws Throwable
     */
    #[DataProvider('invalidThreads')]
    #[Test]
    public function rejectsInvalidThreadIds(string $platform, string $nativeId): void
    {
        $becoming = $this->chain();

        $refusal = null;
        try {
            $becoming(new IncomingMessage($platform, $nativeId, 'hello'));
        } catch (InvalidArgumentException $caught) {
            $refusal = $caught;
        }

        self::assertInstanceOf(
            InvalidArgumentException::class,
            $refusal,
            "\"{$platform}\" and \"{$nativeId}\" were accepted.",
        );

        self::assertSame([], $this->records()->starts(), 'No agent may be started for a bad thread.');
        self::assertDirectoryDoesNotExist("{$this->repository}/.worktrees");
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidThreads(): iterable
    {
        yield 'dot dot in the native id' => ['cli', 'a..b'];
        yield 'native id is exactly dot dot' => ['cli', '..'];
        yield 'slash in the native id' => ['cli', 'a/b'];
        yield 'slash in the platform' => ['c/li', 'x'];
        yield 'empty platform' => ['', 'x'];
        yield 'empty native id' => ['cli', ''];
        yield 'colon in the platform' => ['a:b', 'x'];
    }

    /**
     * Two turns on one thread: the second one knows what the first one was told.
     *
     * @throws Throwable
     */
    #[Test]
    public function carriesTheFirstTurnIntoTheSecond(): void
    {
        $second = self::nth($this->turns(['the word is banana', 'what was the word?']), index: 1);

        self::assertStringContainsString('previous input: the word is banana', $second->reply);
    }

    /**
     * The second turn lands in the directory the first one made, rather than in one of its own.
     *
     * @throws Throwable
     */
    #[Test]
    public function usesTheSameWorktreeTwice(): void
    {
        $turns = $this->turns(['first', 'second']);
        $first = self::nth($turns, index: 0);
        $second = self::nth($turns, index: 1);

        self::assertSame($first->workspace->worktree, $second->workspace->worktree);
        self::assertSame([$first->workspace->worktree], self::directoriesIn("{$this->repository}/.worktrees"));
    }

    /**
     * What the agent said reaches the front end as it is said, and the reply is ended once.
     *
     * @throws Throwable
     */
    #[Test]
    public function appendsTheReplyToTheStream(): void
    {
        $completed = $this->answer('hello');

        $stream = $this->egress()->last();
        self::assertGreaterThan(1, count($stream->appends), 'The reply must arrive in pieces, not in one go.');
        self::assertSame($completed->reply, $stream->joined());
        self::assertSame(1, $stream->closes);
        self::assertSame([[$completed->workspace->thread->value, 'Working on it.']], $this->egress()->statuses);
    }

    /**
     * A tool call is announced to the reader without becoming part of the answer.
     *
     * @throws Throwable
     */
    #[Test]
    public function announcesToolStartsApartFromTheReply(): void
    {
        $this->useScenario(['turns' => ['1' => ['tool' => ['name' => 'Grep', 'id' => 'toolu_1', 'result' => 'ok']]]]);

        $completed = $this->answer('look something up');

        $announcements = array_filter($this->egress()->last()->appends, static fn(string $append): bool => str_contains(
            $append,
            'Grep',
        ));
        self::assertCount(1, $announcements, 'The tool must be announced exactly once.');
        self::assertStringNotContainsString('Grep', $completed->reply, 'The announcement is not the answer.');
        self::assertStringContainsString('fake reply to: look something up', $completed->reply);
    }

    /**
     * The end of a tool call is announced the way its start was, and stays out of the answer.
     *
     * It names the call rather than the tool because that is all {@see ToolCompleted} carries.
     * Nothing produces the event yet, so it is handed over by a stand-in execution layer rather
     * than by the fake CLI.
     *
     * @param bool   $success how the call went
     * @param string $notice  what a reader is shown about it
     *
     * @throws Throwable
     */
    #[DataProvider('toolOutcomes')]
    #[Test]
    public function announcesToolCompletionApartFromTheReply(bool $success, string $notice): void
    {
        $runner = new StubAgentRunner([
            new TextDelta('hi'),
            new ToolCompleted('toolu_1', $success),
            new TurnCompleted(success: true, sessionId: 'session'),
        ]);
        $becoming = $this->chain($runner);

        $completed = $becoming(new IncomingMessage(self::PLATFORM, self::NATIVE_ID, 'hello'));

        self::assertInstanceOf(CompletedTurn::class, $completed);
        self::assertSame('hi', $completed->reply, 'The announcement is not the answer.');
        self::assertSame(['hi', $notice], $this->egress()->last()->appends);
    }

    /** @return iterable<string, array{bool, string}> */
    public static function toolOutcomes(): iterable
    {
        yield 'a call that went well' => [true, "\n> toolu_1 done\n"];
        yield 'a call that did not' => [false, "\n> toolu_1 failed\n"];
    }

    /**
     * An event the pipeline has no arm for ends the turn as a failed one, and the reply is still
     * ended.
     *
     * Dropping it instead would be the failure nobody sees: the reader gets an answer that is
     * missing whatever the new event carried, and the turn is a completed one. Coming back as a
     * {@see FailedTurn} rather than as a throw is what lets a caller be handed the failure the way
     * it is handed an answer — the stream being closed once is the other half, since a reader left
     * with an open reply is how a turn that stopped would show up in Slack.
     *
     * @throws Throwable
     */
    #[Test]
    public function failsATurnOnAnEventNoArmHandles(): void
    {
        $becoming = $this->chain(new StubAgentRunner([new TextDelta('hi'), new UnknownAgentEvent()]));
        $egress = $this->egress();

        $failed = $becoming(new IncomingMessage(self::PLATFORM, self::NATIVE_ID, 'hello'));

        self::assertInstanceOf(FailedTurn::class, $failed);
        self::assertSame('No arm for ' . UnknownAgentEvent::class . '.', $failed->error);
        self::assertSame('hi', $failed->reply, 'What the reader was told before it stopped.');
        self::assertSame(['hi', $failed->error], $egress->last()->appends);
        self::assertSame(1, $egress->last()->closes, 'The reader was left with an open reply.');
    }

    /**
     * Nothing after the event no arm handles is taken: the turn stopped there.
     *
     * @throws Throwable
     */
    #[Test]
    public function takesNothingAfterAnEventNoArmHandles(): void
    {
        $becoming = $this->chain(new StubAgentRunner([
            new UnknownAgentEvent(),
            new TextDelta('and then some'),
            new TurnCompleted(success: true, sessionId: 'session'),
        ]));
        $egress = $this->egress();

        $failed = $becoming(new IncomingMessage(self::PLATFORM, self::NATIVE_ID, 'hello'));

        self::assertInstanceOf(FailedTurn::class, $failed, 'A completion event after the stop counted.');
        self::assertSame('', $failed->reply);
        self::assertSame([$failed->error], $egress->last()->appends);
    }

    /**
     * A turn that ends badly is a turn of its own kind, not an exception and not an answer.
     *
     * @throws Throwable
     */
    #[Test]
    public function reportsAFailedTurn(): void
    {
        $this->useScenario(['turns' => ['1' => ['text' => 'no luck', 'is_error' => true]]]);

        $failed = $this->answer('hello');

        self::assertInstanceOf(FailedTurn::class, $failed);
        self::assertSame('', $failed->error, 'The turn ended; nothing failed on the way.');
    }

    /**
     * An agent that cannot be started is reported to the reader, and the reply is still ended.
     *
     * @throws Throwable
     */
    #[Test]
    public function reportsAnAgentError(): void
    {
        $failed = $this->answer('hello', binary: '/nonexistent/agent-bridge-claude');

        self::assertInstanceOf(FailedTurn::class, $failed);
        self::assertNotSame('', $failed->error);
        self::assertSame($failed->error, $this->egress()->last()->joined());
        self::assertSame(1, $this->egress()->last()->closes);
    }

    /**
     * Drives one turn through the chain and gives the child up afterwards.
     *
     * @throws Throwable
     */
    private function answer(
        string $text,
        string $platform = self::PLATFORM,
        string $nativeId = self::NATIVE_ID,
        ?string $binary = null,
    ): Turn {
        $turns = $this->turns([$text], $platform, $nativeId, $this->cliRunner($binary ?? ClaudeBinary::fake()));

        return self::nth($turns, index: 0);
    }

    /**
     * Drives one turn after another on one thread, and lets go of the child at the end.
     *
     * Everything happens inside one coroutine because the execution layer waits on channels, and
     * the child is given up before it ends: the pool watches its processes on a coroutine of its
     * own, and `Swoole\Coroutine\run()` does not return while that watch is still running.
     *
     * @param list<string> $texts one turn each, in order
     *
     * @return list<Turn>
     *
     * @throws Throwable
     */
    private function turns(
        array $texts,
        string $platform = self::PLATFORM,
        string $nativeId = self::NATIVE_ID,
        ?AgentRunner $runner = null,
    ): array {
        $runner ??= $this->cliRunner(ClaudeBinary::fake());
        $becoming = $this->chain($runner);

        $completed = [];
        Coro::run(static function () use ($becoming, $runner, $texts, $platform, $nativeId, &$completed): void {
            foreach ($texts as $text) {
                $completed[] = $becoming(new IncomingMessage($platform, $nativeId, $text));
            }

            $last = $completed[count($completed) - 1] ?? null;
            if ($last instanceof Turn) {
                $runner->close($last->workspace->thread);
            }
        });

        $turns = [];
        foreach ($completed as $turn) {
            self::assertInstanceOf(Turn::class, $turn);
            $turns[] = $turn;
        }

        return $turns;
    }

    /** @return BecomingInterface the chain, resolved the way a served process resolves it */
    private function chain(?AgentRunner $runner = null): BecomingInterface
    {
        $egress = new RecordingChatEgress();
        $this->egresses[] = $egress;
        $module = new PipelineModule(
            PipelineModule::worktreesOf($this->repository),
            $runner ?? $this->cliRunner(ClaudeBinary::fake()),
            $egress,
        );

        return new Injector(new BeModule(
            AgentBridge::SEMANTIC_NAMESPACE,
            $module,
        ))->getInstance(BecomingInterface::class);
    }

    /** @return PersistentCliRunner the execution layer of the real kind, pointed at a fake binary */
    private function cliRunner(string $binary): PersistentCliRunner
    {
        $worktrees = PipelineModule::worktreesOf($this->repository);
        $settings = new ClaudeCliSettings(binary: $binary, closeGraceSeconds: 2.0);
        $limits = new LifecycleSettings();

        return new PersistentCliRunner(
            new ProcessRecipe(new WorktreeWorkingDirectory($worktrees), new ClaudeCliCommand($settings)),
            new ClaudeCliEventParser(),
            new TurnLocks(),
            new ProcessPool($limits, $settings->closeGraceSeconds),
            $limits->turnSeconds,
        );
    }

    /** @return RecordingChatEgress the front end the chain of this case was built with */
    private function egress(): RecordingChatEgress
    {
        $egress = $this->egresses[count($this->egresses) - 1] ?? null;
        self::assertInstanceOf(RecordingChatEgress::class, $egress, 'No chain has been built yet.');

        return $egress;
    }

    /**
     * @param list<Turn> $turns
     *
     * @return Turn the one at that position, which has to be there
     */
    private static function nth(array $turns, int $index): Turn
    {
        $turn = $turns[$index] ?? null;
        self::assertInstanceOf(Turn::class, $turn, "Turn {$index} never happened.");

        return $turn;
    }

    /** @param array<string, mixed> $specification what the fake should do, turn by turn */
    private function useScenario(array $specification): void
    {
        $path = "{$this->home}/scenario.json";
        $json = json_encode($specification);
        self::assertIsString($json);
        file_put_contents($path, $json);
        putenv("FAKE_CLAUDE_SCENARIO={$path}");
    }

    /** @return FakeCliRecords what the fake wrote down about this case */
    private function records(): FakeCliRecords
    {
        return new FakeCliRecords($this->home);
    }

    /** @return list<string> the directories directly inside the given path, sorted */
    private static function directoriesIn(string $path): array
    {
        $entries = glob("{$path}/*");
        self::assertIsArray($entries);

        return array_values(array_filter($entries, is_dir(...)));
    }
}
