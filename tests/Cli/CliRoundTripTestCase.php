<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Cli;

use NaokiTsuchiya\AgentBridge\Cli\CliCommand;
use NaokiTsuchiya\AgentBridge\Di\BaseRepositoryProvider;
use NaokiTsuchiya\AgentBridge\Tests\Runner\FakeCliRecords;
use NaokiTsuchiya\AgentBridge\Tests\Support\ClaudeBinary;
use NaokiTsuchiya\AgentBridge\Tests\Support\CliProcess;
use NaokiTsuchiya\AgentBridge\Tests\Support\ExecutablePath;
use NaokiTsuchiya\AgentBridge\Tests\Support\GitRepository;
use NaokiTsuchiya\AgentBridge\Tests\Support\Json;
use NaokiTsuchiya\AgentBridge\Tests\Support\TempDir;
use NaokiTsuchiya\RayDiContext\Exception\InvalidAppMeta;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;
use function count;
use function dirname;
use function file_put_contents;
use function glob;
use function implode;
use function in_array;
use function is_dir;
use function is_string;
use function json_encode;
use function str_starts_with;

use const PHP_BINARY;

/**
 * The whole thing, as a person at a terminal gets it: a thread id, a line of input, an answer.
 *
 * Every case starts `bin/agent-bridge-cli` as a real process. That is the point — a worktree that
 * only exists because a test made it, or a session that only continues because a runner was kept
 * alive by the test process, would prove nothing about what a user gets. What the process is given
 * is a repository of its own, a `PATH` on which `claude` is the fake, and the scripts a real
 * compile wrote.
 *
 * **Which execution layer answers is the subclass's only word in this.** Every case here is about
 * the front end, and the front end is supposed not to know which runner is behind it, so the same
 * cases run against both — from one set of expectations, by handing over a different app dir.
 *
 * @internal
 *
 * @mago-expect lint:too-many-methods
 */
abstract class CliRoundTripTestCase extends TestCase
{
    /** The thread every case that does not care about the id uses. */
    private const string THREAD = 'cli:my-experiment';

    /** What {@see self::THREAD} derives to, from the vectors in docs/poc-design.md. */
    private const string WORKTREE = 'cli-my-experiment';

    /** How long a child of this test is given; a fake turn is over in milliseconds. */
    private const float PATIENCE = 30.0;

    /** Where the fake keeps this case's sessions and recordings. */
    private string $home = '';

    /** The repository the worktrees are cut from. */
    private string $repository = '';

    /** The `PATH` the started process is given, on which `claude` is the fake. */
    private string $path = '';

    /**
     * @return string the compiled scripts a started process resolves from, which is the one place
     *                the execution layer under test is chosen
     */
    abstract protected function appDir(): string;

    /** {@inheritDoc} */
    #[Override]
    protected function setUp(): void
    {
        $this->home = TempDir::make('cli-home');
        $this->repository = GitRepository::make('cli-repo');
        $this->path = ExecutablePath::answering(TempDir::make('cli-bin'), ClaudeBinary::fake());
    }

    /** {@inheritDoc} */
    #[Override]
    protected function tearDown(): void
    {
        TempDir::remove($this->path);
        TempDir::remove($this->home);
        GitRepository::remove($this->repository);
    }

    /**
     * One message in, one answer out.
     *
     * @throws InvalidAppMeta
     */
    #[Test]
    public function answersOneTurnOnStandardOutput(): void
    {
        $run = $this->roundTrip(self::THREAD, ['what is the weather']);

        self::assertSame(0, $run->code, $run->errors);
        self::assertStringContainsString('fake reply to: what is the weather', $run->output);
    }

    /**
     * The message reaches the agent exactly as it was typed, newline and all left behind.
     *
     * @throws InvalidAppMeta
     */
    #[Test]
    public function handsTheMessageOverUnchanged(): void
    {
        $this->roundTrip(self::THREAD, ['what is the weather']);

        // The closing quote is what makes this an assertion about trimming: a message that kept
        // its newline would read "what is the weather\n" here. Only the quotes are asserted around
        // it, because how the message reaches the agent — a line of stdin or an argument — is the
        // execution layer's business and not the front end's.
        self::assertStringContainsString('"what is the weather"', $this->handedOver());
    }

    /**
     * Something is shown before there is any answer to show, and not on the answer's stream.
     *
     * @throws InvalidAppMeta
     */
    #[Test]
    public function showsAStatusWhileThereIsNoReplyYet(): void
    {
        $run = $this->roundTrip(self::THREAD, ['hello']);

        self::assertStringContainsString('# Working on it.', $run->errors);
        self::assertStringNotContainsString('Working on it.', $run->output);
    }

    /**
     * A tool call arrives as a line a reader can tell from the answer, and the answer stays clean.
     *
     * @throws InvalidAppMeta
     */
    #[Test]
    public function announcesAToolInALineOfItsOwn(): void
    {
        $scenario = $this->scenario([
            'turns' => ['1' => ['tool' => ['name' => 'Grep', 'id' => 't1', 'result' => 'ok']]],
        ]);

        $run = $this->roundTrip(self::THREAD, ['look something up'], scenario: $scenario);

        $announcements = array_values(array_filter($run->lines, static fn(string $l): bool => str_starts_with(
            $l,
            '> ',
        )));
        self::assertSame(['> Grep'], $announcements);
        $body = array_values(array_filter($run->lines, static fn(string $l): bool => !str_starts_with($l, '> ')));
        self::assertSame(['fake reply to: look something up'], $body);
    }

    /**
     * The thread's worktree is there afterwards, and the turn ran inside it.
     *
     * @throws InvalidAppMeta
     */
    #[Test]
    public function cutsTheThreadsWorktree(): void
    {
        $this->roundTrip(self::THREAD, ['hello']);

        $worktree = "{$this->repository}/.worktrees/" . self::WORKTREE;
        self::assertDirectoryExists($worktree);
        self::assertSame($worktree, Json::text($this->records()->starts()[0] ?? [], 'cwd'));
    }

    /**
     * Two messages in one process: the second answer knows what the first one said.
     *
     * @throws InvalidAppMeta
     */
    #[Test]
    public function carriesTheFirstTurnIntoTheSecond(): void
    {
        $run = $this->roundTrip(self::THREAD, ['the word is banana', 'what was the word?']);

        self::assertSame(0, $run->code, $run->errors);
        self::assertStringContainsString('previous input: the word is banana', $run->output);
    }

    /**
     * Both turns happen in the one directory the first one made.
     *
     * @throws InvalidAppMeta
     */
    #[Test]
    public function usesOneWorktreeForBothTurns(): void
    {
        $this->roundTrip(self::THREAD, ['first', 'second']);

        self::assertCount(2, $this->records()->spans());
        self::assertSame(["{$this->repository}/.worktrees/" . self::WORKTREE], $this->worktrees());
    }

    /**
     * A line with nothing on it is not a question, so it is not a turn either.
     *
     * @throws InvalidAppMeta
     */
    #[Test]
    public function answersEveryLineButTheBlankOnes(): void
    {
        $run = $this->roundTrip(self::THREAD, ['first', '', '   ', 'second']);

        self::assertSame(0, $run->code, $run->errors);
        self::assertCount(2, $this->records()->spans());
    }

    /**
     * The risk the whole design rests on: nothing is stored, so a new process has to find the
     * thread's directory and its session by deriving them again.
     *
     * @throws InvalidAppMeta
     */
    #[Test]
    public function resumesTheThreadAfterTheProcessHasGone(): void
    {
        $first = $this->roundTrip(self::THREAD, ['the word is banana']);
        self::assertSame(0, $first->code, $first->errors);
        $before = count($this->records()->starts());

        $second = $this->roundTrip(self::THREAD, ['what was the word?']);

        self::assertSame(0, $second->code, $second->errors);
        self::assertStringContainsString('previous input: the word is banana', $second->output);
        self::assertSame(["{$this->repository}/.worktrees/" . self::WORKTREE], $this->worktrees());
        // The second run had to start an agent of its own — the first one's died with its process
        // — and it started it in the directory the first run had made.
        self::assertGreaterThan($before, count($this->records()->starts()));
        self::assertSame([$this->worktree()], $this->startDirectories());
    }

    /**
     * Two threads at the same time: two directories, two conversations, nothing shared.
     *
     * The worktrees are cut by a first round trip before the two run together, because two `git
     * worktree add` in one repository race for the same lock and one of them loses.
     *
     * @throws InvalidAppMeta
     */
    #[Test]
    public function keepsConcurrentThreadsApart(): void
    {
        $this->roundTrip('cli:alpha', ['warm up alpha']);
        $this->roundTrip('cli:beta', ['warm up beta']);

        $alpha = $this->start('cli:alpha', ['what was the word?']);
        $beta = $this->start('cli:beta', ['and here?']);
        $together = [$this->finish($alpha), $this->finish($beta)];

        self::assertSame(0, $together[0]->code, $together[0]->errors);
        self::assertSame(0, $together[1]->code, $together[1]->errors);
        self::assertStringContainsString('previous input: warm up alpha', $together[0]->output);
        self::assertStringNotContainsString('beta', $together[0]->output);
        self::assertStringContainsString('previous input: warm up beta', $together[1]->output);
        self::assertStringNotContainsString('alpha', $together[1]->output);
        self::assertSame(
            ["{$this->repository}/.worktrees/cli-alpha", "{$this->repository}/.worktrees/cli-beta"],
            $this->worktrees(),
        );
    }

    /**
     * A thread id the application would refuse never becomes a worktree or a process.
     *
     * @throws InvalidAppMeta
     */
    #[DataProvider('unusableThreadIds')]
    #[Test]
    public function refusesAThreadIdItCannotUse(string $thread): void
    {
        $run = $this->roundTrip($thread, ['hello']);

        self::assertSame(2, $run->code, "\"{$thread}\" was accepted.");
        self::assertNotSame('', $run->errors);
        self::assertSame([], $this->records()->starts());
        self::assertDirectoryDoesNotExist("{$this->repository}/.worktrees");
    }

    /** @return iterable<string, array{string}> */
    public static function unusableThreadIds(): iterable
    {
        yield 'no separator' => ['nocolon'];
        yield 'nothing after the separator' => ['cli:'];
        yield 'nothing before the separator' => [':x'];
        yield 'a native id that climbs out' => ['cli:a..b'];
        yield 'a native id with a slash' => ['cli:a/b'];
        yield 'a platform with a slash' => ['c/li:x'];
    }

    /**
     * Nothing to answer is not a failure.
     *
     * @throws InvalidAppMeta
     */
    #[Test]
    public function endsWithoutATurnWhenNothingArrives(): void
    {
        $run = $this->roundTrip(self::THREAD, []);

        self::assertSame(0, $run->code, $run->errors);
        self::assertSame('', $run->output);
        self::assertSame([], $this->records()->starts());
        self::assertDirectoryDoesNotExist("{$this->repository}/.worktrees");
    }

    /**
     * A turn that ends badly is reported in the exit code, not only in the text.
     *
     * @throws InvalidAppMeta
     */
    #[Test]
    public function reportsAFailedTurn(): void
    {
        $scenario = $this->scenario(['turns' => ['1' => ['is_error' => true]]]);

        $run = $this->roundTrip(self::THREAD, ['hello'], scenario: $scenario);

        self::assertSame(1, $run->code);
    }

    /**
     * One bad turn is enough, whichever of them it was.
     *
     * @throws InvalidAppMeta
     */
    #[Test]
    public function failsWhenAnyTurnFailed(): void
    {
        $scenario = $this->scenario(['turns' => ['1' => ['is_error' => true]]]);

        $run = $this->roundTrip(self::THREAD, ['first', 'second'], scenario: $scenario);

        self::assertSame(1, $run->code);
        self::assertStringContainsString('fake reply to: second', $run->output);
    }

    /**
     * An agent that is nowhere to be found is said so to the reader, and counted as a failure.
     *
     * @throws InvalidAppMeta
     */
    #[Test]
    public function reportsAnAgentItCannotRun(): void
    {
        $path = ExecutablePath::withoutAnAgent(TempDir::make('cli-bin-empty'));

        $run = $this->roundTrip(self::THREAD, ['hello'], path: $path);
        TempDir::remove($path);

        self::assertSame(1, $run->code);
        self::assertStringContainsString('The agent ended before finishing the turn', $run->output);
    }

    /**
     * A repository no worktree can be cut from stops the turn with a reason rather than a trace.
     *
     * @throws InvalidAppMeta
     */
    #[Test]
    public function reportsARepositoryItCannotCutAWorktreeFrom(): void
    {
        $notARepository = TempDir::make('cli-not-a-repo');

        $run = $this->roundTrip(self::THREAD, ['hello'], repository: $notARepository);
        TempDir::remove($notARepository);

        self::assertSame(1, $run->code);
        self::assertStringContainsString('Cannot resolve the default branch', $run->errors);
    }

    /**
     * Drives one process from start to finish.
     *
     * @param list<string> $lines one message each, in order
     *
     * @throws InvalidAppMeta
     */
    private function roundTrip(
        string $thread,
        array $lines,
        ?string $scenario = null,
        ?string $path = null,
        ?string $repository = null,
    ): CliRun {
        return $this->finish($this->start($thread, $lines, $scenario, $path, $repository));
    }

    /**
     * Starts one process and hands it its messages, without waiting for the answers.
     *
     * @param list<string> $lines
     *
     * @throws InvalidAppMeta
     */
    private function start(
        string $thread,
        array $lines,
        ?string $scenario = null,
        ?string $path = null,
        ?string $repository = null,
    ): CliProcess {
        $environment = [
            'PATH' => $path ?? $this->path,
            'FAKE_CLAUDE_HOME' => $this->home,
            BaseRepositoryProvider::VARIABLE => $repository ?? $this->repository,
            CliCommand::APP_DIR => $this->appDir(),
        ];
        if ($scenario !== null) {
            $environment['FAKE_CLAUDE_SCENARIO'] = $scenario;
        }

        $root = dirname(__DIR__, levels: 2);
        $process = CliProcess::start([PHP_BINARY, "{$root}/bin/agent-bridge-cli", $thread], $root, $environment);
        foreach ($lines as $line) {
            $process->sendRaw($line);
        }

        $process->closeStdin();

        return $process;
    }

    /** @return CliRun what the process wrote and what it ended with */
    private function finish(CliProcess $process): CliRun
    {
        $code = $process->waitForExit(self::PATIENCE);
        $lines = $process->lines();
        $errors = $process->stderr();
        $process->stop();

        self::assertIsInt($code, 'The process never ended.');

        return new CliRun($code, implode("\n", $lines), $lines, $errors);
    }

    /**
     * @param array<string, mixed> $specification what the fake should do, turn by turn
     *
     * @return string the path to hand the fake
     */
    private function scenario(array $specification): string
    {
        $path = "{$this->home}/scenario.json";
        $json = json_encode($specification);
        self::assertIsString($json);
        file_put_contents($path, $json);

        return $path;
    }

    /** @return FakeCliRecords what the fake wrote down about this case */
    private function records(): FakeCliRecords
    {
        return new FakeCliRecords($this->home);
    }

    /**
     * @return string everything the agent that answered was given, as one blob to search: the
     *                arguments it was started with and every line it was handed afterwards. Both
     *                are here because which of the two carries the message is the execution
     *                layer's business
     */
    private function handedOver(): string
    {
        $records = $this->records();
        $starts = $records->starts();
        // The last one is the process that answered: a thread whose derived session is not there
        // yet is started twice, and only the second of those ever gets as far as a turn.
        $answering = Json::integer($starts[count($starts) - 1] ?? [], 'pid');
        self::assertIsInt($answering, 'The fake was never started.');

        $handed = '';
        foreach ($records->entriesOf($answering) as $entry) {
            // Each argument is quoted here for the same reason the stdin lines arrive quoted: what
            // the assertion is about is where the message ends, and only a delimiter shows that.
            foreach (array_filter(Json::node($entry, 'argv'), is_string(...)) as $argument) {
                $handed .= "\"{$argument}\"\n";
            }

            $handed .= Json::text($entry, 'line') ?? '';
        }

        return $handed;
    }

    /** @return string the directory {@see self::THREAD} works in */
    private function worktree(): string
    {
        return "{$this->repository}/.worktrees/" . self::WORKTREE;
    }

    /** @return list<string> the directories the agents were started in, without repeats */
    private function startDirectories(): array
    {
        $directories = [];
        foreach ($this->records()->starts() as $start) {
            $cwd = Json::text($start, 'cwd');
            if ($cwd === null || in_array($cwd, $directories, strict: true)) {
                continue;
            }

            $directories[] = $cwd;
        }

        return $directories;
    }

    /** @return list<string> the worktrees that exist under the case's repository, sorted */
    private function worktrees(): array
    {
        $entries = glob("{$this->repository}/.worktrees/*");
        self::assertIsArray($entries);

        return array_values(array_filter($entries, is_dir(...)));
    }
}
