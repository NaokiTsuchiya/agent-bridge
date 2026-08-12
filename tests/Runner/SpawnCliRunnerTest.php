<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Runner;

use Closure;
use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Event\AgentError;
use NaokiTsuchiya\AgentBridge\Event\ClaudeCliEventParser;
use NaokiTsuchiya\AgentBridge\Event\TextDelta;
use NaokiTsuchiya\AgentBridge\Event\TurnCompleted;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliSettings;
use NaokiTsuchiya\AgentBridge\Runner\LifecycleSettings;
use NaokiTsuchiya\AgentBridge\Runner\SpawnCliRunner;
use NaokiTsuchiya\AgentBridge\Tests\Fake\Claude\FakeHome;
use NaokiTsuchiya\AgentBridge\Tests\Fake\Claude\SessionStore;
use NaokiTsuchiya\AgentBridge\Tests\Support\ClaudeBinary;
use NaokiTsuchiya\AgentBridge\Tests\Support\Coro;
use NaokiTsuchiya\AgentBridge\Tests\Support\Json;
use NaokiTsuchiya\AgentBridge\Tests\Support\TempDir;
use NaokiTsuchiya\AgentBridge\Thread\ThreadDerivation;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

use function chmod;
use function count;
use function file_get_contents;
use function file_put_contents;
use function json_encode;
use function microtime;
use function posix_kill;
use function putenv;
use function realpath;
use function substr_count;

/**
 * The second execution layer against the fake CLI, where its every behaviour is pinned.
 *
 * Each case gets a `FAKE_CLAUDE_HOME` and a working directory of its own: sessions are keyed by the
 * working directory and the recordings are shared state, so two cases under one root would see each
 * other's. The binary is always the fake, handed over as a setting — the unit group must not depend
 * on a logged-in Claude Code being installed.
 *
 * What separates these cases from {@see PersistentCliRunnerTest} is not the assertions about a
 * reply but the ones about processes: a turn here is a process, two turns are two processes, and
 * nothing of either is still around afterwards.
 *
 * @mago-expect lint:too-many-methods
 */
final class SpawnCliRunnerTest extends TestCase
{
    /** Where the fake keeps this case's sessions and recordings. */
    private string $home = '';

    /** The directory the children are started in, resolved because the fake keys sessions by it. */
    private string $cwd = '';

    /** A home and a working directory of this case's own, and the fake pointed at them. */
    #[Override]
    protected function setUp(): void
    {
        $this->home = TempDir::make('spawn-home');
        $cwd = realpath(TempDir::make('spawn-cwd'));
        // macOS hands a process /private/var where the test saw /var, and the fake keys its
        // sessions by sha1(getcwd()); seeding one under the unresolved path would never match.
        self::assertIsString($cwd);
        $this->cwd = $cwd;

        putenv("FAKE_CLAUDE_HOME={$this->home}");
    }

    /** The environment is process-wide, so it is put back the way it was found. */
    #[Override]
    protected function tearDown(): void
    {
        putenv('FAKE_CLAUDE_HOME');
        putenv('FAKE_CLAUDE_SCENARIO');
        TempDir::remove($this->home);
        TempDir::remove($this->cwd);
    }

    /**
     * A turn arrives as a run of text and exactly one boundary, in that order.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function deliversTextAndThenOneTurnBoundary(): void
    {
        $runner = $this->runner();
        $thread = new ThreadId('slack:1800000001.000100');
        $this->seedSession($thread);

        Coro::run(static function () use ($runner, $thread): void {
            $events = Events::collect($runner->send($thread, 'hello there'));

            self::assertGreaterThan(0, Events::tally($events, TextDelta::class), 'No text arrived.');
            self::assertSame(1, Events::tally($events, TurnCompleted::class));
            self::assertSame(0, Events::tally($events, AgentError::class));

            $last = Events::last($events);
            self::assertInstanceOf(TurnCompleted::class, $last, 'The turn boundary must come last.');
            self::assertTrue($last->success);
            self::assertStringContainsString('hello there', Events::text($events));
        });
    }

    /**
     * Two turns on one thread are two processes, and the second one still knows the first.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function keepsContextAcrossProcesses(): void
    {
        $runner = $this->runner();
        $thread = new ThreadId('slack:1800000002.000100');

        Coro::run(static function () use ($runner, $thread): void {
            Events::collect($runner->send($thread, 'the word is banana'));
            $second = Events::collect($runner->send($thread, 'what was the word?'));

            self::assertStringContainsString('banana', Events::text($second));
        });

        $records = $this->records();
        self::assertCount(2, $records->turnPids(), 'Each turn must have been answered by a process of its own.');
        self::assertCount(4, $records->turns(), 'Two turns, each recorded as a start and an end.');
    }

    /**
     * Nothing of the child outlives the turn it answered.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function holdsNoChildAfterTheTurn(): void
    {
        $runner = $this->runner();
        $thread = new ThreadId('slack:1800000003.000100');
        $this->seedSession($thread);

        Coro::run(static function () use ($runner, $thread): void {
            Events::collect($runner->send($thread, 'hello'));
        });

        // Deliberately without close(): a runner that needs to be told to let go of a process is
        // the resident one, and this is the other implementation.
        self::assertFalse(posix_kill($this->lastPid(), signal: 0), 'The child is still alive.');
    }

    /**
     * A thread nobody has spoken to yet: the guess fails, and the prompt is handed to a new session.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function startsANewSessionWhenTheDerivedOneIsGone(): void
    {
        $runner = $this->runner();
        $thread = new ThreadId('slack:1800000004.000100');

        Coro::run(static function () use ($runner, $thread): void {
            $events = Events::collect($runner->send($thread, 'pineapple please'));

            $last = Events::last($events);
            self::assertInstanceOf(TurnCompleted::class, $last);
            self::assertTrue($last->success, 'The held prompt must be answered by the second process.');
            self::assertStringContainsString('pineapple please', Events::text($events));
        });

        $records = $this->records();
        self::assertCount(2, $records->starts());
        self::assertContains('--resume', $records->argumentsOf(0));
        self::assertNotContains('--session-id', $records->argumentsOf(0));
        self::assertContains('--session-id', $records->argumentsOf(1));
        self::assertNotContains('--resume', $records->argumentsOf(1));
    }

    /**
     * A resumed turn that failed having said nothing is retried once, and the retry is reported.
     *
     * This is the price of not being able to ask whether the process is still alive: to a runner
     * whose processes always end, a session that is gone and a session whose first turn failed look
     * the same. The retry runs into the id that is already taken, and the caller is told the turn
     * failed — which it had.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function retriesOnceWhenAResumedTurnFailsWithoutOutput(): void
    {
        $thread = new ThreadId('slack:1800000005.000100');
        $this->seedSession($thread);
        // An empty reply is what keeps this in the ambiguous state: any text would reach the caller
        // first and settle that the session was there.
        $this->useScenario(['turns' => ['1' => ['text' => '', 'is_error' => true]]]);
        $runner = $this->runner();

        Coro::run(static function () use ($runner, $thread): void {
            $events = Events::collect($runner->send($thread, 'hello'));

            $failure = Events::last($events);
            self::assertInstanceOf(AgentError::class, $failure);
            self::assertStringContainsString('already in use', $failure->message);
        });

        self::assertCount(2, $this->records()->starts(), 'Exactly one retry.');
    }

    /**
     * A turn that failed after saying something is a failed turn, not a missing session.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function passesOnAFailedTurnThatAlreadySaidSomething(): void
    {
        $thread = new ThreadId('slack:1800000006.000100');
        $this->seedSession($thread);
        $this->useScenario(['turns' => ['1' => ['text' => 'half an answer', 'is_error' => true]]]);
        $runner = $this->runner();

        Coro::run(static function () use ($runner, $thread): void {
            $events = Events::collect($runner->send($thread, 'hello'));

            $completed = Events::last($events);
            self::assertInstanceOf(TurnCompleted::class, $completed);
            self::assertFalse($completed->success);
            self::assertStringContainsString('half an answer', Events::text($events));
        });

        self::assertCount(1, $this->records()->starts(), 'A session that answered must not be given up on.');
    }

    /**
     * A process that dies in the middle of a turn ends it with a reason.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function reportsAProcessThatDiedMidTurn(): void
    {
        $thread = new ThreadId('slack:1800000007.000100');
        $this->seedSession($thread);
        $this->useScenario(['turns' => ['1' => ['crash' => 3]]]);
        $runner = $this->runner();

        Coro::run(static function () use ($runner, $thread): void {
            $events = Events::collect($runner->send($thread, 'hello'));

            self::assertSame(0, Events::tally($events, TurnCompleted::class), 'The turn never finished.');
            self::assertGreaterThan(0, Events::tally($events, TextDelta::class), 'What it did say must arrive.');
            $failure = Events::last($events);
            self::assertInstanceOf(AgentError::class, $failure);
            // The exit code itself is not asserted: the child is asked about the moment its output
            // ends, which is before the system has necessarily reaped it, and an uncollected child
            // reports no code at all.
            self::assertStringContainsString('The agent ended before finishing the turn', $failure->message);
        });

        self::assertCount(1, $this->records()->starts(), 'A process that spoke must not be retried.');
    }

    /**
     * A turn that outstays its allowance ends, and takes its child with it.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function endsTheTurnWhenItOverstaysTheAllowance(): void
    {
        $thread = new ThreadId('slack:1800000008.000100');
        $this->seedSession($thread);
        $this->useScenario(['turns' => ['1' => ['hang' => true]]]);
        $runner = $this->runner(turnSeconds: 0.3);

        $elapsed = 0.0;
        Coro::run(static function () use ($runner, $thread, &$elapsed): void {
            $started = microtime(true);
            $events = Events::collect($runner->send($thread, 'hello'));
            $elapsed = microtime(true) - $started;

            $failure = Events::last($events);
            self::assertInstanceOf(AgentError::class, $failure);
            self::assertStringContainsString('within 0.3 seconds', $failure->message);
        });

        self::assertGreaterThanOrEqual(0.3, $elapsed);
        self::assertLessThan(5.0, $elapsed, 'The turn must end rather than wait for the child forever.');
        self::assertFalse(posix_kill($this->lastPid(), signal: 0), 'The child is still alive.');
    }

    /**
     * A binary that cannot run is reported, not thrown.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function reportsAnErrorWhenTheBinaryCannotRun(): void
    {
        $runner = $this->runner(binary: '/nonexistent/agent-bridge-claude');
        $thread = new ThreadId('slack:1800000009.000100');

        Coro::run(static function () use ($runner, $thread): void {
            $events = Events::collect($runner->send($thread, 'hello'));

            self::assertCount(1, $events);
            self::assertInstanceOf(AgentError::class, Events::last($events));
        });
    }

    /**
     * The fallback is used once and then given up on.
     *
     * The binary here counts its own starts and refuses twice; from the third start it would answer
     * a turn, so an implementation that keeps falling back finishes with a boundary event instead of
     * hanging — a failed assertion rather than a test that never returns.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function stopsAfterOneFallback(): void
    {
        $counter = "{$this->home}/starts.log";
        $runner = $this->runner(binary: $this->countingBinary($counter));
        $thread = new ThreadId('slack:1800000010.000100');

        Coro::run(static function () use ($runner, $thread): void {
            $events = Events::collect($runner->send($thread, 'hello'));

            self::assertCount(1, $events);
            self::assertInstanceOf(AgentError::class, Events::last($events));
        });

        self::assertSame(2, substr_count(self::read($counter), needle: "\n"), 'Exactly two attempts, then done.');
    }

    /**
     * Every flag on the command line, including the injected allow-list and the prompt.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function buildsTheCommandLineFromTheSettings(): void
    {
        $runner = $this->runner(tools: ['Glob', 'WebFetch']);
        $thread = new ThreadId('slack:1800000011.000100');
        $this->seedSession($thread);

        Coro::run(static function () use ($runner, $thread): void {
            Events::collect($runner->send($thread, 'hello'));
        });

        self::assertSame(
            [
                '--print',
                '--output-format',
                'stream-json',
                '--verbose',
                '--include-partial-messages',
                '--allowedTools',
                'Glob,WebFetch',
                '--resume',
                ThreadDerivation::sessionId($thread),
                'hello',
            ],
            $this->records()->argumentsOf(0),
        );
    }

    /**
     * The prompt goes on the command line, so nothing is ever written to the child.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function handsNothingOverOnStandardInput(): void
    {
        $runner = $this->runner();
        $thread = new ThreadId('slack:1800000012.000100');
        $this->seedSession($thread);

        Coro::run(static function () use ($runner, $thread): void {
            Events::collect($runner->send($thread, 'hello'));
        });

        self::assertSame([], $this->records()->inputPids());
    }

    /**
     * The child's input is closed, which is the only way it ever reaches its end.
     *
     * The fake cannot answer this one: a one-shot run never reads stdin, so its recordings look the
     * same either way. The binary here does read it — to the end — before it answers, so a runner
     * that left the pipe open would run into the turn's allowance instead of into an answer.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function closesTheChildsStandardInput(): void
    {
        $received = "{$this->home}/stdin.txt";
        $marker = "{$this->home}/stdin-ended";
        $runner = $this->runner(binary: $this->inputReadingBinary($received, $marker), turnSeconds: 2.0);
        $thread = new ThreadId('slack:1800000013.000100');

        Coro::run(static function () use ($runner, $thread): void {
            $events = Events::collect($runner->send($thread, 'hello'));

            $completed = Events::last($events);
            self::assertInstanceOf(TurnCompleted::class, $completed, 'The child never got to the end of its input.');
            self::assertTrue($completed->success);
        });

        self::assertStringContainsString('ended', self::read($marker));
        self::assertSame('', self::read($received), 'Nothing may be written to a one-shot child.');
    }

    /**
     * The working directory comes from the collaborator, not from anything the runner works out.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function startsTheProcessWhereTheResolverSays(): void
    {
        $directories = new FixedWorkingDirectory($this->cwd);
        $runner = $this->runner(directories: $directories);
        $thread = new ThreadId('slack:1800000014.000100');
        $this->seedSession($thread);

        Coro::run(static function () use ($runner, $thread): void {
            Events::collect($runner->send($thread, 'hello'));
        });

        self::assertSame([$thread->value], $directories->asked);
        self::assertSame($this->cwd, Json::text($this->records()->starts()[0] ?? [], 'cwd'));
    }

    /**
     * Closing holds nothing, before a turn, after one, and twice over.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function closingHoldsNothing(): void
    {
        $runner = $this->runner();
        $thread = new ThreadId('slack:1800000015.000100');
        $this->seedSession($thread);

        Coro::run(function () use ($runner, $thread): void {
            $runner->close($thread);
            self::assertSame([], $this->records()->starts(), 'Closing a thread must not start anything.');

            Events::collect($runner->send($thread, 'hello'));
            $runner->close($thread);
            $runner->close($thread);

            // The thread is as usable after two closes as it was before the first.
            $again = Events::collect($runner->send($thread, 'and again'));
            $last = Events::last($again);
            self::assertInstanceOf(TurnCompleted::class, $last);
            self::assertTrue($last->success);
        });
    }

    /**
     * One thread answers one turn at a time, whoever asks.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function answersOneTurnAtATimePerThread(): void
    {
        $this->useScenario(['default' => ['delay_ms' => 300]]);
        $runner = $this->runner();
        $thread = new ThreadId('slack:1800000016.000100');
        $this->seedSession($thread);

        $this->together([
            static function () use ($runner, $thread): void {
                Events::collect($runner->send($thread, 'first'));
            },
            static function () use ($runner, $thread): void {
                Events::collect($runner->send($thread, 'second'));
            },
        ]);

        [$first, $second] = $this->twoTurns();
        self::assertFalse($first->overlaps($second), 'Two turns of one thread ran at the same time.');
        self::assertTrue($second->startedAfter($first));
    }

    /**
     * Two threads do not wait for each other, which is what a per-thread lock is for.
     *
     * A lock taken under one key for every thread would keep the case above green and only show up
     * here, as two turns that could have run together and did not.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function letsTwoThreadsRunAtTheSameTime(): void
    {
        $this->useScenario(['default' => ['delay_ms' => 300]]);
        $runner = $this->runner();
        $first = new ThreadId('slack:1800000017.000100');
        $second = new ThreadId('slack:1800000017.000200');
        $this->seedSession($first);
        $this->seedSession($second);

        $this->together([
            static function () use ($runner, $first): void {
                Events::collect($runner->send($first, 'over here'));
            },
            static function () use ($runner, $second): void {
                Events::collect($runner->send($second, 'over there'));
            },
        ]);

        [$first, $second] = $this->twoTurns();
        self::assertTrue($first->overlaps($second), 'Two threads were serialized against each other.');
    }

    /**
     * @param list<string>|null $tools
     *
     * @return SpawnCliRunner pointed at the fake unless another binary is named
     */
    private function runner(
        ?string $binary = null,
        ?array $tools = null,
        float $turnSeconds = 30.0,
        ?FixedWorkingDirectory $directories = null,
    ): SpawnCliRunner {
        return new SpawnCliRunner(
            $directories ?? new FixedWorkingDirectory($this->cwd),
            new ClaudeCliSettings(
                binary: $binary ?? ClaudeBinary::fake(),
                allowedTools: $tools ?? ClaudeCliSettings::READ_ONLY_TOOLS,
            ),
            new ClaudeCliEventParser(),
            new LifecycleSettings(turnSeconds: $turnSeconds),
        );
    }

    /**
     * Runs the bodies together and reports what any of them raised.
     *
     * @param list<Closure(): void> $bodies
     *
     * @throws Throwable whatever the first of them raised
     */
    private function together(array $bodies): void
    {
        $failures = [];
        Coro::run(static function () use ($bodies, &$failures): void {
            $done = new Channel(count($bodies));
            foreach ($bodies as $body) {
                // A failed assertion inside a coroutine is a fatal error rather than a reported
                // failure, so each of them keeps whatever it raised for the outside to re-throw.
                Coroutine::create(static function () use ($body, $done, &$failures): void {
                    try {
                        $body();
                    } catch (Throwable $error) {
                        $failures[] = $error;
                    }

                    $done->push(true);
                });
            }

            foreach ($bodies as $_) {
                $done->pop(20.0);
            }
        });

        $failure = $failures[0] ?? null;
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * @return array{TurnSpan, TurnSpan} the two turns the fake recorded, oldest first
     */
    private function twoTurns(): array
    {
        $spans = $this->records()->spans();
        self::assertCount(2, $spans);
        $first = $spans[0] ?? null;
        $second = $spans[1] ?? null;
        self::assertInstanceOf(TurnSpan::class, $first);
        self::assertInstanceOf(TurnSpan::class, $second);

        return [$first, $second];
    }

    /** Puts the thread's derived session in place, so that the first `--resume` finds it. */
    private function seedSession(ThreadId $thread): void
    {
        $store = new SessionStore(FakeHome::fromEnvironment(), $this->cwd);
        $store->create(ThreadDerivation::sessionId($thread));
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

    /**
     * A `claude`-shaped binary that records every start and refuses the first two.
     *
     * @param string $counter the file it appends one line to per start
     *
     * @return string the path to the binary
     */
    private function countingBinary(string $counter): string
    {
        $path = "{$this->home}/counting-claude";
        file_put_contents($path, <<<SH
            #!/bin/sh
            printf '%s\\n' "\$*" >> '{$counter}'
            if [ "\$(wc -l < '{$counter}' | tr -d ' ')" -le 2 ]; then exit 1; fi
            printf '{"type":"result","subtype":"success","is_error":false,"session_id":"x","result":"late"}\\n'
            SH);
        chmod($path, permissions: 0o755);

        return $path;
    }

    /**
     * A `claude`-shaped binary that answers only once its input has ended.
     *
     * @param string $received where whatever was written to it is kept
     * @param string $marker   written after the input reached its end
     *
     * @return string the path to the binary
     */
    private function inputReadingBinary(string $received, string $marker): string
    {
        $path = "{$this->home}/stdin-claude";
        file_put_contents($path, <<<SH
            #!/bin/sh
            cat > '{$received}'
            printf 'ended\\n' > '{$marker}'
            printf '{"type":"result","subtype":"success","is_error":false,"session_id":"x","result":"done"}\\n'
            SH);
        chmod($path, permissions: 0o755);

        return $path;
    }

    /** @return FakeCliRecords what the fake wrote down about this case */
    private function records(): FakeCliRecords
    {
        return new FakeCliRecords($this->home);
    }

    /** @return int the pid of the last child the fake recorded */
    private function lastPid(): int
    {
        $starts = $this->records()->starts();
        $pid = Json::integer($starts[count($starts) - 1] ?? [], 'pid');
        self::assertIsInt($pid, 'No child was recorded.');

        return $pid;
    }

    /** @return string the file's contents, asserted to be readable */
    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, "Could not read {$path}.");

        return $contents;
    }
}
