<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\FakeClaude;

use NaokiTsuchiya\AgentBridge\Event\ClaudeCliEventParser;
use NaokiTsuchiya\AgentBridge\Event\TextDelta;
use NaokiTsuchiya\AgentBridge\Event\ToolStarted;
use NaokiTsuchiya\AgentBridge\Event\TurnCompleted;
use NaokiTsuchiya\AgentBridge\Tests\Support\ClaudeBinary;
use NaokiTsuchiya\AgentBridge\Tests\Support\CliProcess;
use NaokiTsuchiya\AgentBridge\Tests\Support\Json;
use NaokiTsuchiya\AgentBridge\Tests\Support\TempDir;
use NaokiTsuchiya\AgentBridge\Tests\Support\Uuid;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function count;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_array;
use function is_executable;
use function is_float;
use function is_string;
use function json_encode;
use function realpath;

/**
 * The fake's own behaviour: everything it offers that the real CLI is not asked about.
 *
 * The shared behaviour is in {@see ClaudeCliContractTestCase}. What is left here is the machinery
 * a test needs and the real binary has no equivalent for — scenario control, the recordings, and
 * the isolation between two tests — plus the exactness the contract deliberately avoids, such as
 * the reply text and the way deltas add up.
 *
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:kan-defect
 * @mago-expect lint:cyclomatic-complexity
 */
final class FakeClaudeCliTest extends TestCase
{
    /** Where one test's sessions and recordings live, so that no two tests share either. */
    private string $home = '';

    /** The directory the fake is started in, which is half of a session's key. */
    private string $cwd = '';

    /** @var list<CliProcess> */
    private array $started = [];

    /** A home and a working directory of this test alone. */
    #[Override]
    protected function setUp(): void
    {
        $this->home = TempDir::make('fake-home');
        $this->cwd = TempDir::make('fake-cwd');
    }

    /** Kills whatever is still running before the directories it wrote into go away. */
    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->started as $process) {
            $process->stop();
        }

        $this->started = [];
        TempDir::remove($this->cwd);
        TempDir::remove($this->home);
    }

    /** The whole point of a repository-local binary: a test can name its path and start it. */
    #[Test]
    public function theBinaryCanBeRunFromItsPathInTheRepository(): void
    {
        $binary = ClaudeBinary::fake();

        self::assertTrue(is_executable($binary), "{$binary} must be executable.");
    }

    /**
     * Flags this project does not use must not change the run, and their values must not be read
     * as the prompt: `--allowedTools Bash` leaving `Bash` behind as a positional argument is the
     * failure this pins down.
     */
    #[Test]
    public function unknownFlagsAreSwallowedAndFlagValuesStayOutOfThePrompt(): void
    {
        $process = $this->oneShot([
            '--session-id',
            Uuid::random(),
            '--verbose',
            '--allowedTools',
            'Bash',
            '--disallowedTools=Write',
            '--totally-unknown-flag',
        ], 'PING');

        self::assertSame(0, $process->waitForExit(30.0), $process->stderr());
        self::assertSame('fake reply to: PING', $this->assistantText($process));
    }

    /**
     * `--version` answers, so that a suite guarded by a version check can be aimed at the fake.
     *
     * The integration group refuses to run unless its binary reports a version; without this the
     * fake could never stand in for the real CLI there, which is the whole point of the switch in
     * {@see ClaudeBinary}.
     */
    #[Test]
    public function theFakeReportsAVersion(): void
    {
        $process = $this->start(['--version']);

        self::assertSame(0, $process->waitForExit(30.0), $process->stderr());
        self::assertMatchesRegularExpression('/\d+\.\d+\.\d+/', implode("\n", $process->lines()));
    }

    /** Only `stream-json` means "stay and read stdin"; anything else is a single shot that ends. */
    #[Test]
    public function anInputFormatOtherThanStreamJsonRunsOnceAndExits(): void
    {
        $process = $this->start(['--session-id', Uuid::random(), '--input-format', 'text', '-p', 'PING']);

        self::assertSame(0, $process->waitForExit(30.0), $process->stderr());
        self::assertSame(1, $process->resultCount());
    }

    /** Sessions are keyed by directory as well as UUID, exactly as the real transcripts are. */
    #[Test]
    public function theSameUuidInAnotherDirectoryIsADifferentSession(): void
    {
        $uuid = Uuid::random();
        $first = $this->oneShot(['--session-id', $uuid], 'ALPHA');
        self::assertSame(0, $first->waitForExit(30.0), $first->stderr());

        $elsewhere = TempDir::make('fake-cwd-2');
        $second = $this->startIn($elsewhere, ['--session-id', $uuid, '-p', '--output-format', 'stream-json', 'BRAVO']);
        $exitCode = $second->waitForExit(30.0);
        $reply = $this->assistantText($second);
        TempDir::remove($elsewhere);

        self::assertSame(0, $exitCode, 'A UUID used in another directory must still be free here.');
        self::assertStringNotContainsString('ALPHA', $reply, 'Context must not cross directories.');
    }

    /**
     * What `--resume` is for: a *new process* picks the history up where the last one left it.
     *
     * The two-turn case inside one process proves nothing about this, because there the previous
     * input is still in the process that answers. Here the only path to it is the stored session.
     */
    #[Test]
    public function resumingASessionInANewProcessAnswersFromTheStoredHistory(): void
    {
        $uuid = Uuid::random();
        $first = $this->oneShot(['--session-id', $uuid], 'ALPHA');
        self::assertSame(0, $first->waitForExit(30.0), $first->stderr());
        self::assertSame('fake reply to: ALPHA', $this->assistantText($first));

        $second = $this->oneShot(['--resume', $uuid], 'BRAVO');
        self::assertSame(0, $second->waitForExit(30.0), $second->stderr());

        self::assertSame('fake reply to: BRAVO | previous input: ALPHA', $this->assistantText($second));
    }

    /** The other half of that key: a session is invisible from a directory that did not start it. */
    #[Test]
    public function aSessionCannotBeResumedFromAnotherDirectory(): void
    {
        $uuid = Uuid::random();
        $first = $this->oneShot(['--session-id', $uuid], 'ALPHA');
        self::assertSame(0, $first->waitForExit(30.0), $first->stderr());

        $elsewhere = TempDir::make('fake-cwd-2');
        $second = $this->startIn($elsewhere, ['--resume', $uuid, '-p', '--output-format', 'stream-json', 'BRAVO']);
        $exitCode = $second->waitForExit(30.0);
        $stderr = $second->stderr();
        TempDir::remove($elsewhere);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('No conversation found', $stderr);
    }

    /** A consumer that forgets `--include-partial-messages` must see no deltas at all, not fewer. */
    #[Test]
    public function deltasAreEmittedOnlyWhenPartialMessagesWereAskedFor(): void
    {
        $without = $this->oneShot(['--session-id', Uuid::random()], 'PING');
        self::assertSame(0, $without->waitForExit(30.0), $without->stderr());
        self::assertNotContains('stream_event', $without->eventTypes());

        $with = $this->oneShot(['--session-id', Uuid::random(), '--include-partial-messages'], 'PING');
        self::assertSame(0, $with->waitForExit(30.0), $with->stderr());
        self::assertContains('stream_event', $with->eventTypes());
    }

    /** A frontend streams the deltas and then trusts the whole message; the two must agree. */
    #[Test]
    public function theDeltasOfATurnJoinIntoExactlyItsAssistantText(): void
    {
        $process = $this->oneShot(['--session-id', Uuid::random(), '--include-partial-messages'], 'PING');
        self::assertSame(0, $process->waitForExit(30.0), $process->stderr());

        $joined = '';
        foreach ($process->decodedLines() as $line) {
            $delta = Json::node(Json::node($line, 'event'), 'delta');
            if (Json::text($delta, 'type') !== 'text_delta') {
                continue;
            }

            $joined .= Json::text($delta, 'text') ?? '';
        }

        self::assertSame($this->assistantText($process), $joined);
        self::assertNotSame('', $joined);
    }

    /** The output is only useful if issue #4's parser reads it without a translation layer. */
    #[Test]
    public function aTurnOfOutputParsesIntoAgentEvents(): void
    {
        $uuid = Uuid::random();
        $scenario = $this->scenario(['default' => ['tool' => ['name' => 'Bash', 'id' => 'toolu_1']]]);
        $process = $this->oneShot(['--session-id', $uuid, '--include-partial-messages'], 'PING', $scenario);
        self::assertSame(0, $process->waitForExit(30.0), $process->stderr());

        $parser = new ClaudeCliEventParser();
        $events = [];
        foreach ($process->lines() as $line) {
            foreach ($parser->parse($line) as $event) {
                $events[] = $event;
            }
        }

        $classes = [];
        foreach ($events as $event) {
            $classes[] = $event::class;
        }

        self::assertSame(TextDelta::class, $classes[0] ?? '');
        self::assertContains(ToolStarted::class, $classes);
        self::assertSame(TurnCompleted::class, $classes[count($classes) - 1] ?? '');
        self::assertEquals(new TurnCompleted(true, $uuid), $events[count($events) - 1] ?? null);
    }

    /** A malformed line is skipped rather than answered, so a bad write cannot desynchronise turns. */
    #[Test]
    public function aLineThatCarriesNoUserTextIsNotATurn(): void
    {
        $process = $this->resident(['--session-id', Uuid::random()]);

        $process->sendRaw('this is not json');
        $process->sendRaw('{"type":"user","message":{"role":"user","content":[]}}');
        $process->send('PING');

        self::assertTrue($process->waitForResults(1), $process->stderr());
        self::assertSame(1, $process->resultCount(), 'Only the readable line is a turn.');
        self::assertTrue($process->isRunning());
    }

    /** The recording is how a test sees that a second process was started, and what it was given. */
    #[Test]
    public function everyRunRecordsItsPidArgumentsCwdAndInput(): void
    {
        $first = $this->oneShot(['--session-id', Uuid::random()], 'PING');
        self::assertSame(0, $first->waitForExit(30.0), $first->stderr());

        $second = $this->resident(['--session-id', Uuid::random()]);
        $second->send('PONG');
        self::assertTrue($second->waitForResults(1), $second->stderr());
        $second->closeStdin();
        self::assertSame(0, $second->waitForExit(30.0));

        $records = $this->records('invocations.jsonl');
        $starts = [];
        $inputs = [];
        foreach ($records as $record) {
            if (Json::text($record, 'event') === 'start') {
                $starts[] = $record;
                continue;
            }

            $inputs[] = Json::text($record, 'line') ?? '';
        }

        self::assertCount(2, $starts, 'Each run appends its own start line.');
        $one = $starts[0] ?? [];
        $two = $starts[1] ?? [];
        self::assertNotSame(Json::integer($one, 'pid'), Json::integer($two, 'pid'));
        // realpath on both sides: macOS hands a process /private/var where the test saw /var.
        self::assertSame(realpath($this->cwd), realpath(Json::text($one, 'cwd') ?? ''));
        self::assertContains('--session-id', Json::node($one, 'argv'));
        self::assertCount(1, $inputs);
        self::assertStringContainsString('PONG', $inputs[0] ?? '');
    }

    /** Serialization can only be checked against timestamps, so every turn leaves both of its edges. */
    #[Test]
    public function eachTurnRecordsWhenItStartedAndWhenItEnded(): void
    {
        $process = $this->resident(['--session-id', Uuid::random()]);
        $process->send('PING');
        self::assertTrue($process->waitForResults(1), $process->stderr());
        $process->send('PONG');
        self::assertTrue($process->waitForResults(2), $process->stderr());

        $records = $this->records('turns.jsonl');
        $shape = [];
        foreach ($records as $record) {
            $turn = Json::integer($record, 'turn') ?? 0;
            $phase = Json::text($record, 'phase') ?? '';
            $shape[] = "{$turn}:{$phase}";
        }

        self::assertSame(['1:start', '1:end', '2:start', '2:end'], $shape);
        self::assertNotNull(Json::text($records[0] ?? [], 'session_id'));
        self::assertNotNull(Json::integer($records[0] ?? [], 'pid'));
        self::assertLessThanOrEqual(
            self::at($records[2] ?? []),
            self::at($records[1] ?? []),
            'Turn 1 must end before turn 2 starts.',
        );
    }

    /** The recorded end has to be the real end, not a timestamp taken before the work. */
    #[Test]
    public function aDelayedTurnTakesAtLeastThatLongBetweenItsStartAndItsEnd(): void
    {
        $scenario = $this->scenario(['default' => ['delay_ms' => 300]]);
        $process = $this->oneShot(['--session-id', Uuid::random()], 'PING', $scenario);
        self::assertSame(0, $process->waitForExit(30.0), $process->stderr());

        $records = $this->records('turns.jsonl');

        self::assertCount(2, $records);
        self::assertGreaterThanOrEqual(0.3, self::at($records[1] ?? []) - self::at($records[0] ?? []));
    }

    /** Two tests running at once must not collide, which is the whole reason the home is replaceable. */
    #[Test]
    public function twoHomesDoNotShareSessionsOrRecordings(): void
    {
        $uuid = Uuid::random();
        $other = TempDir::make('fake-home-2');

        $first = $this->oneShot(['--session-id', $uuid], 'PING');
        self::assertSame(0, $first->waitForExit(30.0), $first->stderr());

        $second = $this->start(['--session-id', $uuid, '-p', '--output-format', 'stream-json', 'PING'], [
            'FAKE_CLAUDE_HOME' => $other,
        ]);
        $exitCode = $second->waitForExit(30.0);
        $records = $this->records('invocations.jsonl', $other);
        TempDir::remove($other);

        self::assertSame(0, $exitCode, 'The same UUID must be free under a different home.');
        self::assertCount(1, $records, 'Each home records only its own runs.');
    }

    /** The binary stays usable by hand, with nothing configured, which is how a failure is debugged. */
    #[Test]
    public function withoutAHomeTheStateGoesUnderTheTemporaryDirectory(): void
    {
        $temp = TempDir::make('fake-tmpdir');
        $process = CliProcess::start(
            [ClaudeBinary::fake(), '--session-id', Uuid::random(), '-p', '--output-format', 'stream-json', 'PING'],
            $this->cwd,
            ['TMPDIR' => $temp],
        );
        $this->started[] = $process;
        $exitCode = $process->waitForExit(30.0);
        $records = $this->records('invocations.jsonl', "{$temp}/fake-claude-cli");
        TempDir::remove($temp);

        self::assertSame(0, $exitCode, $process->stderr());
        self::assertCount(1, $records);
    }

    /** A scenario path that cannot be read is a mistake in the test, not a reason to improvise. */
    #[Test]
    public function anUnreadableScenarioStopsTheRun(): void
    {
        $process = $this->oneShot(['--session-id', Uuid::random()], 'PING', "{$this->home}/absent.json");

        self::assertSame(2, $process->waitForExit(30.0));
        self::assertStringContainsString('cannot read scenario file', $process->stderr());
    }

    /**
     * A file that reads but does not parse is the same mistake, and must be as loud.
     *
     * Answering it with the plain behaviour would leave a test that quietly asserts nothing it
     * meant to: the scenario it wrote would simply not be in effect.
     */
    #[Test]
    public function aScenarioThatIsNotJsonStopsTheRun(): void
    {
        $path = "{$this->home}/broken.json";
        file_put_contents($path, data: 'not json{');

        $process = $this->oneShot(['--session-id', Uuid::random()], 'PING', $path);

        self::assertSame(2, $process->waitForExit(30.0));
        self::assertStringContainsString('cannot read scenario file', $process->stderr());
    }

    /** The reply itself is what most tests need to pin down. */
    #[Test]
    public function aScenarioCanDictateTheReplyText(): void
    {
        $scenario = $this->scenario(['default' => ['text' => 'canned reply']]);
        $process = $this->oneShot(['--session-id', Uuid::random()], 'PING', $scenario);
        self::assertSame(0, $process->waitForExit(30.0), $process->stderr());

        self::assertSame('canned reply', $this->assistantText($process));
    }

    /** Naming no session at all is a legal way to start: the fake invents one, as the CLI does. */
    #[Test]
    public function aRunThatNamesNoSessionGetsOne(): void
    {
        $process = $this->start(['-p', '--output-format', 'stream-json', 'PING']);
        self::assertSame(0, $process->waitForExit(30.0), $process->stderr());

        $sessionId = Json::text($this->resultLine($process), 'session_id') ?? '';

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $sessionId);
        self::assertSame('fake reply to: PING', $this->assistantText($process));
    }

    /** Tool events can be provoked without a model deciding to call one. */
    #[Test]
    public function aScenarioCanPutAToolCallInATurn(): void
    {
        $scenario = $this->scenario([
            'turns' => ['1' => ['tool' => ['name' => 'Read', 'id' => 'toolu_42', 'result' => 'contents']]],
        ]);
        $process = $this->oneShot(['--session-id', Uuid::random()], 'PING', $scenario);
        self::assertSame(0, $process->waitForExit(30.0), $process->stderr());

        self::assertStringContainsString('"tool_use"', implode("\n", $process->lines()));
        self::assertStringContainsString('toolu_42', implode("\n", $process->lines()));
        self::assertContains('user', $process->eventTypes(), 'A tool call is answered by a tool_result line.');
    }

    /** The error path of a turn is reachable on demand. */
    #[Test]
    public function aScenarioCanMakeATurnFail(): void
    {
        $scenario = $this->scenario(['default' => ['is_error' => true]]);
        $process = $this->oneShot(['--session-id', Uuid::random()], 'PING', $scenario);
        self::assertSame(0, $process->waitForExit(30.0), $process->stderr());

        $result = $this->resultLine($process);
        self::assertTrue(Json::flag($result, 'is_error'));
        self::assertSame('error_during_execution', Json::text($result, 'subtype'));
    }

    /** Process death mid-turn is what a caller has to recover from, so it has to be producible. */
    #[Test]
    public function aScenarioCanEndTheProcessInTheMiddleOfATurn(): void
    {
        $scenario = $this->scenario(['default' => ['crash' => 9]]);
        $process = $this->resident(['--session-id', Uuid::random()], $scenario);
        $process->send('PING');

        self::assertSame(9, $process->waitForExit(30.0));
        self::assertSame(0, $process->resultCount(), 'A crashed turn never reaches its result line.');
        self::assertContains('assistant', $process->eventTypes(), 'Output before the crash is kept.');

        // A turn that died has a start and no end. Recording one anyway would tell whoever reads
        // turns.jsonl to judge serialization that the turn finished, which it never did.
        $records = $this->records('turns.jsonl');
        self::assertCount(1, $records);
        self::assertSame(1, Json::integer($records[0] ?? [], 'turn'));
        self::assertSame('start', Json::text($records[0] ?? [], 'phase'));
    }

    /** A turn timeout can only be tested against a process that genuinely never answers. */
    #[Test]
    public function aScenarioCanMakeATurnNeverAnswer(): void
    {
        $scenario = $this->scenario(['default' => ['hang' => true]]);
        $process = $this->resident(['--session-id', Uuid::random()], $scenario);
        $process->send('PING');

        self::assertFalse($process->waitForResults(1, timeout: 2.0), 'A hanging turn must not answer.');
        self::assertTrue($process->isRunning(), 'A hanging turn keeps the process alive.');
    }

    /** The default reply is what makes a turn traceable to its input, and to the turn before it. */
    #[Test]
    public function theDefaultReplyRepeatsTheInputAndTheOneBefore(): void
    {
        $process = $this->resident(['--session-id', Uuid::random()]);
        $process->send('ALPHA');
        self::assertTrue($process->waitForResults(1), $process->stderr());
        $process->send('BRAVO');
        self::assertTrue($process->waitForResults(2), $process->stderr());

        self::assertSame(
            ['fake reply to: ALPHA', 'fake reply to: BRAVO | previous input: ALPHA'],
            $this->assistantTexts($process),
        );
    }

    /**
     * @param list<string> $arguments
     * @param string|null  $scenario the scenario file to point the run at, if any
     */
    private function oneShot(array $arguments, string $prompt, ?string $scenario = null): CliProcess
    {
        $command = [...$arguments, '-p', '--output-format', 'stream-json', $prompt];

        return $this->start($command, $scenario === null ? [] : ['FAKE_CLAUDE_SCENARIO' => $scenario]);
    }

    /**
     * @param list<string> $arguments
     * @param string|null  $scenario the scenario file to point the run at, if any
     */
    private function resident(array $arguments, ?string $scenario = null): CliProcess
    {
        $command = [...$arguments, '-p', '--input-format', 'stream-json', '--output-format', 'stream-json'];

        return $this->start($command, $scenario === null ? [] : ['FAKE_CLAUDE_SCENARIO' => $scenario]);
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $env
     */
    private function start(array $arguments, array $env = []): CliProcess
    {
        return $this->startIn($this->cwd, $arguments, $env);
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $env
     */
    private function startIn(string $cwd, array $arguments, array $env = []): CliProcess
    {
        $process = CliProcess::start([ClaudeBinary::fake(), ...$arguments], $cwd, [
            'FAKE_CLAUDE_HOME' => $this->home,
            ...$env,
        ]);
        $this->started[] = $process;

        return $process;
    }

    /**
     * @param array<string, mixed> $scenario
     *
     * @return string the path to write it at, which the run is pointed at
     */
    private function scenario(array $scenario): string
    {
        $path = "{$this->home}/scenario.json";
        $json = json_encode($scenario);
        file_put_contents($path, $json === false ? '{}' : $json);

        return $path;
    }

    /** @return list<array<array-key, mixed>> the lines of a recording file, in order */
    private function records(string $file, ?string $home = null): array
    {
        $directory = $home ?? $this->home;
        $raw = file_get_contents("{$directory}/{$file}");
        if (!is_string($raw)) {
            return [];
        }

        $records = [];
        foreach (explode("\n", $raw) as $line) {
            $record = $line === '' ? null : Json::decode($line);
            if ($record === null) {
                continue;
            }

            $records[] = $record;
        }

        return $records;
    }

    /** @param array<array-key, mixed> $record @return float the moment it was recorded */
    private static function at(array $record): float
    {
        return is_float($record['at'] ?? null) ? $record['at'] : 0.0;
    }

    /** @return array<array-key, mixed> the turn's result line */
    private function resultLine(CliProcess $process): array
    {
        foreach ($process->decodedLines() as $line) {
            if (Json::text($line, 'type') !== 'result') {
                continue;
            }

            return $line;
        }

        return [];
    }

    /** @return string every assistant message of the run, joined */
    private function assistantText(CliProcess $process): string
    {
        return implode('', $this->assistantTexts($process));
    }

    /** @return list<string> one entry per assistant message that carried text */
    private function assistantTexts(CliProcess $process): array
    {
        $texts = [];
        foreach ($process->decodedLines() as $line) {
            if (Json::text($line, 'type') !== 'assistant') {
                continue;
            }

            $text = '';
            foreach (array_filter(Json::node(Json::node($line, 'message'), 'content'), is_array(...)) as $block) {
                $text .= Json::text($block, 'text') ?? '';
            }

            if ($text === '') {
                continue;
            }

            $texts[] = $text;
        }

        return $texts;
    }
}
