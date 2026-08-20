<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\FakeClaude;

use NaokiTsuchiya\AgentBridge\Support\CliProcess;
use NaokiTsuchiya\AgentBridge\Support\Json;
use NaokiTsuchiya\AgentBridge\Support\TempDir;
use NaokiTsuchiya\AgentBridge\Support\Uuid;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_slice;
use function count;
use function implode;

/**
 * The behaviour the fake promises to share with the real `claude`, asserted against both.
 *
 * The subclasses differ in one thing: which binary to run. Everything asserted here therefore has
 * to hold for a language model as much as for a canned reply, which rules out comparing reply
 * text: assertions are exit codes, the presence and position of event types, and keywords that
 * were asked for. A test that pinned the wording would pass on the fake and fail on the real CLI
 * for a reason that has nothing to do with the contract.
 *
 * When one of these fails on the real side, the real side is right and the fake is what changes.
 *
 * It is the fake's promise, so it lives with the fake rather than in `tests/`: that keeps the
 * fake's suite from reaching into the host package's test namespace, and leaves the real side
 * ({@see \NaokiTsuchiya\AgentBridge\Integration\RealClaudeCliContractTest}) as the one
 * crossing over — the direction `tests/` already depends in.
 *
 * @internal
 *
 * @mago-expect lint:too-many-methods
 */
abstract class ClaudeCliContractTestCase extends TestCase
{
    /** The directory the processes of one test run in, which scopes their sessions. */
    protected string $cwd = '';

    /** @var list<CliProcess> */
    private array $started = [];

    /** @return list<string> the binary under contract, as it is invoked */
    abstract protected function binary(): array;

    /** @return array<string, string> environment the binary needs on top of the caller's */
    abstract protected function environment(): array;

    /** A working directory of this test alone, since it is half of a session key. */
    #[Override]
    protected function setUp(): void
    {
        $this->cwd = TempDir::make('contract');
    }

    /** Kills whatever is still running, so that no process outlives the test that started it. */
    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->started as $process) {
            $process->stop();
        }

        $this->started = [];
        TempDir::remove($this->cwd);
    }

    /** The plainest run there is: a new session identifier is accepted and a turn completes. */
    #[Test]
    public function aFreshSessionIdStartsASession(): void
    {
        $process = $this->oneShot(['--session-id', Uuid::random()], 'Reply with the single word ALPHA.');

        self::assertSame(0, $process->waitForExit(180.0), $process->stderr());
        self::assertContains('result', $process->eventTypes());
        self::assertFalse(Json::flag($this->lastResult($process), 'is_error'));
    }

    /** `--session-id` opens a session and `--resume` continues one; neither does the other's job. */
    #[Test]
    public function aSessionIdIsRefusedTheSecondTimeButResumeIsNot(): void
    {
        $uuid = Uuid::random();
        $first = $this->oneShot(['--session-id', $uuid], 'Reply with the single word ALPHA.');
        self::assertSame(0, $first->waitForExit(180.0), $first->stderr());

        $second = $this->oneShot(['--session-id', $uuid], 'Reply with the single word ALPHA.');
        self::assertSame(1, $second->waitForExit(180.0));
        self::assertStringContainsString('already in use', $second->stderr());

        $resumed = $this->oneShot(['--resume', $uuid], 'Reply with the single word CHARLIE.');
        self::assertSame(0, $resumed->waitForExit(180.0), $resumed->stderr());
    }

    /** A caller has to be able to tell a lost session from a working one, by exit code alone. */
    #[Test]
    public function resumingASessionThatWasNeverStartedFails(): void
    {
        $process = $this->oneShot(['--resume', Uuid::random()], 'Reply with the single word ALPHA.');

        self::assertSame(1, $process->waitForExit(180.0));
        self::assertStringContainsString('No conversation found', $process->stderr());
    }

    /**
     * The one behaviour a caller cannot discover by asking: nothing is ever sent to this process.
     *
     * A resident process is normally judged by what comes back after a turn is written to it. Here
     * the process is gone before there is anything to write, so a caller that waits for its own
     * turn to complete waits forever; the test mirrors that by writing nothing at all.
     */
    #[Test]
    public function aResidentProcessResumingAnUnknownSessionEndsBeforeAnyInput(): void
    {
        $process = $this->resident(['--resume', Uuid::random()]);

        self::assertSame(1, $process->waitForExit(60.0));
        self::assertSame(['result'], $process->eventTypes());
        self::assertSame('error_during_execution', Json::text($this->lastResult($process), 'subtype'));
    }

    /** The reason a process is kept alive at all: the second turn still knows the first. */
    #[Test]
    public function contextSurvivesFromOneTurnToTheNextInsideOneProcess(): void
    {
        $process = $this->resident(['--session-id', Uuid::random()]);
        $process->send('Remember this word: BRAVO. Reply with just that word.');
        self::assertTrue($process->waitForResults(1), $process->stderr());

        $process->send('Which word did I ask you to remember? Answer with just that word.');
        self::assertTrue($process->waitForResults(2), $process->stderr());

        self::assertStringContainsStringIgnoringCase('BRAVO', $this->secondTurn($process));
    }

    /** Where a turn ends, and that the end of a turn is not the end of the process. */
    #[Test]
    public function everyTurnEndsWithItsResultLineAndTheProcessOutlivesIt(): void
    {
        $process = $this->resident(['--session-id', Uuid::random()]);

        $process->send('Reply with the single word ALPHA.');
        self::assertTrue($process->waitForResults(1), $process->stderr());
        self::assertSame('result', $this->lastEventType($process));
        self::assertTrue($process->isRunning(), 'The process must survive the end of a turn.');

        $process->send('Reply with the single word DELTA.');
        self::assertTrue($process->waitForResults(2), $process->stderr());
        self::assertSame('result', $this->lastEventType($process));
        self::assertSame(2, $process->resultCount());
        self::assertTrue($process->isRunning(), 'The process must survive the end of a turn.');
    }

    /** Closing stdin is how a caller shuts a process down, and it is not an error. */
    #[Test]
    public function closingStdinEndsTheProcessWithoutError(): void
    {
        $process = $this->resident(['--session-id', Uuid::random()]);

        $process->closeStdin();

        self::assertSame(0, $process->waitForExit(60.0), $process->stderr());
    }

    /** @param list<string> $arguments the session flags this run needs */
    protected function oneShot(array $arguments, string $prompt): CliProcess
    {
        $process = $this->start([...$arguments, '-p', '--output-format', 'stream-json', '--verbose', $prompt]);
        // Closed at once: the real CLI waits three seconds for a prompt on stdin it will not get,
        // and says so on stderr, which would drown the message a test is looking for.
        $process->closeStdin();

        return $process;
    }

    /** @param list<string> $arguments the session flags this run needs */
    protected function resident(array $arguments): CliProcess
    {
        return $this->start([
            ...$arguments,
            '-p',
            '--input-format',
            'stream-json',
            '--output-format',
            'stream-json',
            '--verbose',
        ]);
    }

    /** @param list<string> $arguments the full argument list after the binary */
    private function start(array $arguments): CliProcess
    {
        $process = CliProcess::start([...$this->binary(), ...$arguments], $this->cwd, $this->environment());
        $this->started[] = $process;

        return $process;
    }

    /** @return array<array-key, mixed> the last result line, or an empty array when none arrived */
    private function lastResult(CliProcess $process): array
    {
        $result = [];
        foreach ($process->decodedLines() as $line) {
            if (Json::text($line, 'type') !== 'result') {
                continue;
            }

            $result = $line;
        }

        return $result;
    }

    /** @return string the `type` of the last line printed so far, or an empty string */
    private function lastEventType(CliProcess $process): string
    {
        $types = $process->eventTypes();

        return $types[count($types) - 1] ?? '';
    }

    /** @return string everything printed after the first turn ended, as one blob to search */
    private function secondTurn(CliProcess $process): string
    {
        $lines = $process->lines();
        $firstResult = 0;
        foreach ($lines as $index => $line) {
            $decoded = Json::decode($line) ?? [];
            if (Json::text($decoded, 'type') !== 'result') {
                continue;
            }

            $firstResult = $index;
            break;
        }

        return implode("\n", array_slice($lines, $firstResult + 1));
    }
}
