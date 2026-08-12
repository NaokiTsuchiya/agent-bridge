<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Cli;

use Be\Framework\BecomingInterface;
use Be\Framework\Module\BeModule;
use NaokiTsuchiya\AgentBridge\AgentBridge;
use NaokiTsuchiya\AgentBridge\Cli\StandardOutputEgress;
use NaokiTsuchiya\AgentBridge\Pipeline\CompletedTurn;
use NaokiTsuchiya\AgentBridge\Pipeline\IncomingMessage;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliSettings;
use NaokiTsuchiya\AgentBridge\Runner\PersistentCliRunner;
use NaokiTsuchiya\AgentBridge\Runner\WorktreeWorkingDirectory;
use NaokiTsuchiya\AgentBridge\Tests\Pipeline\PipelineModule;
use NaokiTsuchiya\AgentBridge\Tests\Support\ClaudeBinary;
use NaokiTsuchiya\AgentBridge\Tests\Support\Coro;
use NaokiTsuchiya\AgentBridge\Tests\Support\GitRepository;
use NaokiTsuchiya\AgentBridge\Tests\Support\RecordingStream;
use NaokiTsuchiya\AgentBridge\Tests\Support\TempDir;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Swoole\Runtime;
use Throwable;

use function count;
use function file_put_contents;
use function json_encode;
use function microtime;
use function putenv;
use function uniqid;

/**
 * How the answer reaches the streams while the turn is still going.
 *
 * The round trip through a started process shows what a reader ends up with; what it cannot show
 * is *when* each piece got there, because a pipe joins everything written into it. So the same
 * chain is driven in this process, against a stream that keeps every write apart
 * ({@see RecordingStream}) — which is the only place the order and the boundaries still exist.
 */
final class CliChainOutputTest extends TestCase
{
    /** The thread these cases use; none of them is about which one it is. */
    private const string PLATFORM = 'cli';

    /** Paired with {@see self::PLATFORM} this is the first vector of docs/poc-design.md. */
    private const string NATIVE_ID = 'my-experiment';

    /** Swoole's hook flags as they were before this class ran. */
    private static int $hookFlags = 0;

    /** Where the fake keeps this case's sessions and recordings. */
    private string $home = '';

    /** The repository the worktrees are cut from. */
    private string $repository = '';

    /** {@inheritDoc} */
    #[Override]
    public static function setUpBeforeClass(): void
    {
        self::$hookFlags = Runtime::getHookFlags();
    }

    /** Building a runner turns Swoole's hooks on process-wide, which is not this class's to leave on. */
    #[Override]
    public static function tearDownAfterClass(): void
    {
        Runtime::setHookFlags(self::$hookFlags);
    }

    /** {@inheritDoc} */
    #[Override]
    protected function setUp(): void
    {
        $this->home = TempDir::make('cli-output-home');
        $this->repository = GitRepository::make('cli-output-repo');

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
     * The reader is told something is happening before there is anything to read.
     *
     * Both streams are one here on purpose: the order of two separate streams is not something
     * anybody can observe, and the order is what this case is about.
     *
     * @throws Throwable
     */
    #[Test]
    public function showsTheStatusBeforeAnyOfTheReply(): void
    {
        $name = uniqid('together-');
        $stream = RecordingStream::open($name);

        $this->answer('hello', $stream, $stream);

        $fragments = RecordingStream::fragments($name);
        self::assertSame("# Working on it.\n", $fragments[0] ?? '');
    }

    /**
     * The reply is written as it is produced, not once the turn is over.
     *
     * Two things say so, and both are needed: the fragments arrived as several writes rather than
     * one, and the first of them was written before the turn had finished. A front end that
     * gathered the whole answer and printed it at the end would pass neither.
     *
     * @throws Throwable
     */
    #[Test]
    public function writesTheReplyAsItArrives(): void
    {
        // The turn takes long enough that "before it finished" is a real interval rather than a
        // few microseconds of parsing.
        $this->useScenario(['turns' => ['1' => ['delay_ms' => 200]]]);
        $name = uniqid('reply-');
        $reply = RecordingStream::open($name);
        $status = RecordingStream::open(uniqid('status-'));

        $completed = $this->answer('hello', $reply, $status);
        $finishedAt = microtime(true);

        $writes = RecordingStream::writes($name);
        self::assertGreaterThan(1, count($writes), 'The reply must arrive in pieces, not in one go.');
        self::assertSame("{$completed->reply}\n", RecordingStream::text($name));
        [, $firstAt] = $writes[0] ?? ['', 0.0];
        self::assertLessThan($finishedAt, $firstAt, 'The first piece must be out before the turn ends.');
    }

    /**
     * A tool call goes out as its own piece, and never becomes part of the answer.
     *
     * @throws Throwable
     */
    #[Test]
    public function announcesAToolAsAPieceOfItsOwn(): void
    {
        $this->useScenario(['turns' => ['1' => ['tool' => ['name' => 'Grep', 'id' => 't1', 'result' => 'ok']]]]);
        $name = uniqid('reply-');
        $reply = RecordingStream::open($name);

        $completed = $this->answer('look something up', $reply, RecordingStream::open(uniqid('status-')));

        self::assertContains("\n> Grep\n", RecordingStream::fragments($name));
        self::assertStringNotContainsString('Grep', $completed->reply);
    }

    /**
     * Drives one turn through the real chain, onto the given streams.
     *
     * @param resource $reply  where the answer is to go
     * @param resource $status where the status is to go
     *
     * @throws Throwable
     */
    private function answer(string $text, mixed $reply, mixed $status): CompletedTurn
    {
        $worktrees = PipelineModule::worktreesOf($this->repository);
        $runner = new PersistentCliRunner(
            new WorktreeWorkingDirectory($worktrees),
            new ClaudeCliSettings(binary: ClaudeBinary::fake(), closeGraceSeconds: 2.0),
        );
        $becoming = new Injector(
            new BeModule(
                AgentBridge::SEMANTIC_NAMESPACE,
                new PipelineModule($worktrees, $runner, new StandardOutputEgress($reply, $status)),
            ),
        )->getInstance(BecomingInterface::class);

        $completed = null;
        Coro::run(static function () use ($becoming, $runner, $text, &$completed): void {
            $completed = $becoming(new IncomingMessage(self::PLATFORM, self::NATIVE_ID, $text));
            if (!$completed instanceof CompletedTurn) {
                return;
            }

            $runner->close($completed->workspace->thread);
        });

        self::assertInstanceOf(CompletedTurn::class, $completed);

        return $completed;
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
}
