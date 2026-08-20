<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Cli;

use Be\Framework\BecomingInterface;
use NaokiTsuchiya\AgentBridge\Di\BaseRepositoryProvider;
use NaokiTsuchiya\AgentBridge\Di\Boot;
use NaokiTsuchiya\AgentBridge\Di\BootException;
use NaokiTsuchiya\AgentBridge\Di\ServeContext;
use NaokiTsuchiya\AgentBridge\Pipeline\CompletedTurn;
use NaokiTsuchiya\AgentBridge\Pipeline\IncomingMessage;
use NaokiTsuchiya\AgentBridge\Runner\AgentRunner;
use NaokiTsuchiya\AgentBridge\Runner\FakeCliRecords;
use NaokiTsuchiya\AgentBridge\Support\ClaudeBinary;
use NaokiTsuchiya\AgentBridge\Support\Coro;
use NaokiTsuchiya\AgentBridge\Support\ExecutablePath;
use NaokiTsuchiya\AgentBridge\Support\GitRepository;
use NaokiTsuchiya\AgentBridge\Support\Json;
use NaokiTsuchiya\AgentBridge\Support\TempDir;
use NaokiTsuchiya\RayDiContext\AppMeta;
use NaokiTsuchiya\RayDiContext\ContextProviderInterface;
use NaokiTsuchiya\RayDiContext\Exception\ContextClassNotFound;
use NaokiTsuchiya\RayDiContext\Exception\ExceptionInterface;
use NaokiTsuchiya\RayDiContext\Exception\InvalidContextClass;
use NaokiTsuchiya\RayDiContext\MapContextProvider;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ray\Di\InjectorInterface;
use Swoole\Runtime;
use Throwable;

use function array_slice;
use function count;
use function getenv;
use function implode;
use function is_string;
use function json_encode;
use function ob_get_clean;
use function ob_start;
use function putenv;

/**
 * The PoC's own risk list, item one: a warmed-up singleton that carries something of one event
 * into the next.
 *
 * #9 could only show that the warmup happens, because there was nothing to process yet. Here there
 * is: one injector, warmed up the way a started process warms it up, and two threads answered one
 * after the other through the very same handler. What settles it is not the replies but what the
 * agent was actually run with — the fake writes down its arguments, its directory and everything
 * it was handed on standard input, and none of the second thread's records may carry anything of
 * the first.
 *
 * The two `# Working on it.` lines this leaves on the terminal are the real front end writing to
 * this process's standard error, which is a file descriptor and cannot be captured from inside.
 *
 * Which compile the injector is built from is the subclass's to say, and saying it is saying which
 * execution layer the warmed-up singleton is: carrying one event into the next is a risk either
 * runner could realize, by different means.
 *
 * @internal
 *
 * @mago-expect lint:too-many-methods
 */
abstract class WarmupIsolationTestCase extends TestCase
{
    /** The thread answered first, and the vector docs/poc-design.md gives for it. */
    private const string FIRST_PLATFORM = 'cli';

    /** @see self::FIRST_PLATFORM */
    private const string FIRST_NATIVE_ID = 'my-experiment';

    /** The session {@see self::FIRST_PLATFORM} derives to. */
    private const string FIRST_SESSION = 'b0f400e4-b88d-5d39-a7ee-6cd49fbc4b39';

    /** What the first thread is told, which the second one must never see. */
    private const string FIRST_TEXT = 'the word is banana';

    /** The thread answered second. */
    private const string SECOND_PLATFORM = 'slack';

    /** @see self::SECOND_PLATFORM */
    private const string SECOND_NATIVE_ID = '1700000001.123456';

    /** The session {@see self::SECOND_PLATFORM} derives to. */
    private const string SECOND_SESSION = '959a94a6-5395-5d07-bc71-0a0c7d800476';

    /** Swoole's hook flags as they were before this class ran. */
    private static int $hookFlags = 0;

    /** The `PATH` this process had before the case pointed it at the fake. */
    private string $path = '';

    /** The directory `claude` is found in while the case runs. */
    private string $bin = '';

    /** Where the fake keeps this case's sessions and recordings. */
    private string $home = '';

    /** The repository the worktrees are cut from. */
    private string $repository = '';

    /**
     * @return AppMeta the compile a started process would resolve from, which is where the
     *                 execution layer under test is chosen
     */
    abstract protected function meta(): AppMeta;

    /** {@inheritDoc} */
    #[Override]
    public static function setUpBeforeClass(): void
    {
        self::$hookFlags = Runtime::getHookFlags();
    }

    /** The execution layer turns Swoole's hooks on process-wide; they go back off here. */
    #[Override]
    public static function tearDownAfterClass(): void
    {
        Runtime::setHookFlags(self::$hookFlags);
    }

    /**
     * The environment a started process has: a repository, and a `claude` on `PATH`.
     *
     * Both are read at the moment they are needed rather than baked into the compiled scripts,
     * which is exactly why a case can put them here.
     */
    #[Override]
    protected function setUp(): void
    {
        $this->path = (string) getenv('PATH');
        $this->bin = TempDir::make('warmup-bin');
        $this->home = TempDir::make('warmup-home');
        $this->repository = GitRepository::make('warmup-repo');

        $found = ExecutablePath::answering($this->bin, ClaudeBinary::fake());
        putenv("PATH={$found}");
        putenv("FAKE_CLAUDE_HOME={$this->home}");
        putenv(BaseRepositoryProvider::VARIABLE . "={$this->repository}");
    }

    /** {@inheritDoc} */
    #[Override]
    protected function tearDown(): void
    {
        putenv("PATH={$this->path}");
        putenv('FAKE_CLAUDE_HOME');
        putenv(BaseRepositoryProvider::VARIABLE);
        TempDir::remove($this->bin);
        TempDir::remove($this->home);
        GitRepository::remove($this->repository);
    }

    /**
     * Two threads, one handler, nothing shared.
     *
     * @throws BootException
     * @throws ExceptionInterface
     * @throws Throwable
     */
    #[Test]
    public function carriesNothingOfOneThreadIntoTheNext(): void
    {
        $injector = $this->boot();
        // Resolved once and used for both messages, the way a resident process uses it. The
        // execution layer behind it is the warmed-up singleton, and asking twice has to give the
        // same one — otherwise "state between events" would be about two different objects.
        $becoming = $injector->getInstance(BecomingInterface::class);
        $runner = $injector->getInstance(AgentRunner::class);
        self::assertSame($runner, $injector->getInstance(AgentRunner::class));

        $processesOfTheFirst = 0;
        $second = null;
        $written = '';
        Coro::run(function () use ($becoming, $runner, &$processesOfTheFirst, &$second, &$written): void {
            // Started inside the coroutine, not around it: Swoole gives a coroutine an output
            // context of its own, and a buffer opened outside never sees what is written in here.
            ob_start();
            $first = $becoming(new IncomingMessage(self::FIRST_PLATFORM, self::FIRST_NATIVE_ID, self::FIRST_TEXT));
            self::assertInstanceOf(CompletedTurn::class, $first);
            $runner->close($first->workspace->thread);
            $processesOfTheFirst = count($this->records()->starts());

            $second = $becoming(new IncomingMessage(
                self::SECOND_PLATFORM,
                self::SECOND_NATIVE_ID,
                'what was the word?',
            ));
            self::assertInstanceOf(CompletedTurn::class, $second);
            $runner->close($second->workspace->thread);

            $captured = ob_get_clean();
            $written = $captured === false ? '' : $captured;
        });

        self::assertInstanceOf(CompletedTurn::class, $second);
        self::assertStringContainsString('fake reply to: what was the word?', $written);
        // The fake answers with the input it was sent before, if it has one for that session. So
        // this is the reply saying, in its own words, that it knows nothing of the first thread.
        self::assertStringNotContainsString(self::FIRST_TEXT, $second->reply, 'The sessions ran together.');

        $evidence = $this->recordsAfter($processesOfTheFirst);
        self::assertStringContainsString(self::SECOND_SESSION, $evidence, 'The second thread ran as itself.');
        foreach ($this->firstThreadsMarks() as $what => $mark) {
            self::assertStringNotContainsString($mark, $evidence, "The second thread saw the first thread's {$what}.");
        }
    }

    /** @return array<string, string> what only the first thread may be described by */
    private function firstThreadsMarks(): array
    {
        return [
            'session' => self::FIRST_SESSION,
            'working directory' => '.worktrees/' . self::FIRST_PLATFORM . '-' . self::FIRST_NATIVE_ID,
            'message' => self::FIRST_TEXT,
            'reply' => 'fake reply to: ' . self::FIRST_TEXT,
        ];
    }

    /**
     * Everything the fake wrote down about the processes started after the first thread was done.
     *
     * @param int $before how many processes had been started by then
     *
     * @return string the arguments, directories and input of the second thread's processes
     */
    private function recordsAfter(int $before): string
    {
        $records = $this->records();
        $evidence = [];
        foreach (array_slice($records->starts(), $before) as $start) {
            $pid = Json::integer($start, 'pid');
            if ($pid === null) {
                continue;
            }

            foreach ($records->entriesOf($pid) as $entry) {
                $line = json_encode($entry);
                $evidence[] = is_string($line) ? $line : '';
            }
        }

        self::assertNotSame([], $evidence, 'The second thread started no agent at all.');

        return implode("\n", $evidence);
    }

    /** @return FakeCliRecords what the fake wrote down about this case */
    private function records(): FakeCliRecords
    {
        return new FakeCliRecords($this->home);
    }

    /**
     * The injector of a started process: compiled scripts, warmed up once.
     *
     * The mapping is the one bootstrap.php returns, built here rather than read from there;
     * {@see CliRoundTripTest} is what runs the script that reads it.
     *
     * @throws BootException
     * @throws ExceptionInterface
     */
    private function boot(): InjectorInterface
    {
        return (new Boot($this->meta(), self::contexts()))();
    }

    /**
     * @return ContextProviderInterface the context-name-to-context mapping
     *
     * @throws ContextClassNotFound
     * @throws InvalidContextClass
     */
    private static function contexts(): ContextProviderInterface
    {
        return new MapContextProvider([ServeContext::NAME => ServeContext::class]);
    }
}
