<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Runner;

use Closure;
use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Event\AgentError;
use NaokiTsuchiya\AgentBridge\Event\TextDelta;
use NaokiTsuchiya\AgentBridge\Event\TurnCompleted;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliSettings;
use NaokiTsuchiya\AgentBridge\Runner\PersistentCliRunner;
use NaokiTsuchiya\AgentBridge\Tests\Support\ClaudeBinary;
use NaokiTsuchiya\AgentBridge\Tests\Support\Coro;
use NaokiTsuchiya\AgentBridge\Tests\Support\Json;
use NaokiTsuchiya\AgentBridge\Thread\ThreadDerivation;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

use function microtime;
use function posix_kill;
use function substr_count;

/**
 * The runner against the fake CLI, which is where every behaviour of the execution layer is pinned.
 *
 * Each case gets a `FAKE_CLAUDE_HOME` and a working directory of its own: sessions are keyed by
 * the working directory, and the recordings are shared state, so two cases under one root would
 * see each other's. The binary is always the fake, handed over as a setting — the unit group must
 * not depend on a logged-in Claude Code being installed.
 *
 * @mago-expect lint:too-many-methods
 */
final class PersistentCliRunnerTest extends FakeCliRunnerTestCase
{
    /** @return string names this case's temp directories, so a stray one is easy to place */
    #[Override]
    protected function homePrefix(): string
    {
        return 'runner';
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
        $thread = new ThreadId('slack:1700000001.000100');

        Coro::run(static function () use ($runner, $thread): void {
            $events = Events::collect($runner->send($thread, 'hello there'));
            $runner->close($thread);

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
     * Two turns on one thread are served by one process, and the second one remembers the first.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function keepsTheContextInsideOneProcess(): void
    {
        $runner = $this->runner();
        $thread = new ThreadId('slack:1700000002.000100');
        $this->seedSession($thread);

        Coro::run(static function () use ($runner, $thread): void {
            Events::collect($runner->send($thread, 'the word is banana'));
            $second = Events::collect($runner->send($thread, 'what was the word?'));
            $runner->close($thread);

            self::assertStringContainsString('banana', Events::text($second));
        });

        $records = $this->records();
        self::assertCount(1, $records->starts(), 'The seeded session must be resumed by one process.');
        self::assertContains('--resume', $records->argumentsOf(0));
        self::assertCount(1, $records->turnPids(), 'Both turns must come from the same process.');
        self::assertCount(1, $records->inputPids());
        self::assertCount(4, $records->turns(), 'Two turns, each recorded as a start and an end.');
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
        $thread = new ThreadId('slack:1700000003.000100');
        $this->seedSession($thread);

        Coro::run(static function () use ($runner, $thread): void {
            Events::collect($runner->send($thread, 'hello'));
            $runner->close($thread);
        });

        self::assertSame([$thread->value], $directories->asked);
        self::assertSame($this->cwd, Json::text($this->records()->starts()[0] ?? [], 'cwd'));
    }

    /**
     * Every flag on the command line, including the injected allow-list.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function buildsTheCommandLineFromTheSettings(): void
    {
        $runner = $this->runner(tools: ['Glob', 'WebFetch']);
        $thread = new ThreadId('slack:1700000004.000100');
        $this->seedSession($thread);

        Coro::run(static function () use ($runner, $thread): void {
            Events::collect($runner->send($thread, 'hello'));
            $runner->close($thread);
        });

        self::assertSame(
            [
                '-p',
                '--input-format',
                'stream-json',
                '--output-format',
                'stream-json',
                '--verbose',
                '--include-partial-messages',
                '--allowedTools',
                'Glob,WebFetch',
                '--resume',
                ThreadDerivation::sessionId($thread),
            ],
            $this->records()->argumentsOf(0),
        );
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
        $thread = new ThreadId('slack:1700000005.000100');

        Coro::run(static function () use ($runner, $thread): void {
            $events = Events::collect($runner->send($thread, 'pineapple please'));
            $runner->close($thread);

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
     * A failed first turn from a process that lives on is a failed turn, not a missing session.
     *
     * Falling back here would start a second process on a session id that exists, which Claude
     * Code refuses outright — so the caller would lose an answer it was entitled to.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function keepsAFailedFirstTurnWhenTheProcessLivesOn(): void
    {
        $thread = new ThreadId('slack:1700000006.000100');
        $this->seedSession($thread);
        // An empty reply is what keeps this in the ambiguous state: any text would reach the
        // caller first and settle that the session was there.
        $this->useScenario(['turns' => ['1' => ['text' => '', 'is_error' => true]]]);
        $runner = $this->runner();

        Coro::run(static function () use ($runner, $thread): void {
            $events = Events::collect($runner->send($thread, 'hello'));
            $runner->close($thread);

            self::assertCount(1, $events);
            $completed = Events::last($events);
            self::assertInstanceOf(TurnCompleted::class, $completed);
            self::assertFalse($completed->success);
        });

        self::assertCount(1, $this->records()->starts(), 'A living process must not be replaced.');
    }

    /**
     * A failed turn from an established process is passed straight through.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function passesOnAFailedTurnFromAnEstablishedProcess(): void
    {
        $thread = new ThreadId('slack:1700000007.000100');
        $this->seedSession($thread);
        $this->useScenario(['turns' => ['2' => ['text' => '', 'is_error' => true]]]);
        $runner = $this->runner();

        Coro::run(static function () use ($runner, $thread): void {
            Events::collect($runner->send($thread, 'hello'));
            $events = Events::collect($runner->send($thread, 'and again'));
            $runner->close($thread);

            self::assertCount(1, $events);
            $completed = Events::last($events);
            self::assertInstanceOf(TurnCompleted::class, $completed);
            self::assertFalse($completed->success);
        });

        self::assertCount(1, $this->records()->starts());
    }

    /**
     * A process that dies mid-turn ends that turn, and the next send quietly starts another.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function startsAnotherProcessAfterOneDiesMidTurn(): void
    {
        $thread = new ThreadId('slack:1700000008.000100');
        $this->useScenario(['turns' => ['2' => ['crash' => 3]]]);
        $runner = $this->runner();

        Coro::run(function () use ($runner, $thread): void {
            Events::collect($runner->send($thread, 'first apricot'));
            self::assertCount(2, $this->records()->starts());

            $crashed = Events::collect($runner->send($thread, 'second mango'));
            self::assertSame(0, Events::tally($crashed, TurnCompleted::class), 'The turn never finished.');
            self::assertInstanceOf(AgentError::class, Events::last($crashed));
            self::assertCount(2, $this->records()->starts(), 'A dead process is not replaced mid-turn.');

            $recovered = Events::collect($runner->send($thread, 'third question'));
            $runner->close($thread);

            $last = Events::last($recovered);
            self::assertInstanceOf(TurnCompleted::class, $last);
            self::assertTrue($last->success);
            self::assertStringContainsString('mango', Events::text($recovered), 'The context is in the transcript.');
        });

        $records = $this->records();
        self::assertCount(3, $records->starts());
        self::assertContains('--resume', $records->argumentsOf(2), 'The session now exists, so it is resumed.');
    }

    /**
     * Closing ends the child, and does not need the grace to do it.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function endsTheChildOnClose(): void
    {
        $runner = $this->runner(grace: 2.0);
        $thread = new ThreadId('slack:1700000009.000100');

        $elapsed = 0.0;
        Coro::run(static function () use ($runner, $thread, &$elapsed): void {
            Events::collect($runner->send($thread, 'hello'));
            $started = microtime(true);
            $runner->close($thread);
            $elapsed = microtime(true) - $started;
        });

        self::assertLessThan(1.0, $elapsed, 'End of input must stop the child, not the grace running out.');
        self::assertFalse(posix_kill($this->lastPid(), signal: 0), 'The child is still alive.');
    }

    /**
     * Waiting for one thread's child to go away must not stop another thread's turn.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function letsAnotherThreadFinishWhileOneIsClosing(): void
    {
        $this->useScenario(['turns' => ['2' => ['hang' => true]]]);
        $runner = $this->runner(grace: 1.0);
        $closing = new ThreadId('slack:1700000010.000100');
        $other = new ThreadId('slack:1700000010.000200');

        $order = [];
        $elapsed = 0.0;
        $failures = [];
        $body = static function () use ($runner, $closing, $other, &$order, &$elapsed, &$failures): void {
            $done = new Channel(2);

            // A failed assertion inside a coroutine is a fatal error, not a reported failure, so
            // each of the two keeps whatever it raised for the test to re-throw once both are done.
            $start = static function (Closure $body) use ($done, &$failures): void {
                Coroutine::create(static function () use ($body, $done, &$failures): void {
                    try {
                        $body();
                    } catch (Throwable $error) {
                        $failures[] = $error;
                    }

                    $done->push(true);
                });
            };

            $start(static function () use ($runner, $closing, &$order, &$elapsed): void {
                Events::collect($runner->send($closing, 'first'));
                // Deliberately not read: the turn is under way, and this one never finishes.
                $runner->send($closing, 'second');
                $started = microtime(true);
                $runner->close($closing);
                $elapsed = microtime(true) - $started;
                $order[] = 'closed';
            });

            $start(static function () use ($runner, $other, &$order): void {
                $events = Events::collect($runner->send($other, 'other thread'));
                $last = Events::last($events);
                self::assertInstanceOf(TurnCompleted::class, $last);
                self::assertTrue($last->success);
                $runner->close($other);
                $order[] = 'other finished';
            });

            $done->pop(20.0);
            $done->pop(20.0);
        };

        Coro::run($body);

        // Raised out here, where a failure is reported as one: inside a coroutine it would be fatal.
        $failure = $failures[0] ?? null;
        if ($failure !== null) {
            throw $failure;
        }

        self::assertGreaterThanOrEqual(1.0, $elapsed, 'The close under test has to outlast the other turn.');
        self::assertSame(['other finished', 'closed'], $order);
    }

    /**
     * A thread picks up where it left off, even though its process is gone.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function continuesTheContextAfterClose(): void
    {
        $runner = $this->runner();
        $thread = new ThreadId('slack:1700000011.000100');
        $this->seedSession($thread);

        Coro::run(static function () use ($runner, $thread): void {
            Events::collect($runner->send($thread, 'the word is kiwi'));
            $runner->close($thread);

            $events = Events::collect($runner->send($thread, 'what was the word?'));
            $runner->close($thread);

            $last = Events::last($events);
            self::assertInstanceOf(TurnCompleted::class, $last);
            self::assertTrue($last->success);
            self::assertStringContainsString('kiwi', Events::text($events));
        });

        $records = $this->records();
        self::assertCount(2, $records->starts());
        self::assertContains('--resume', $records->argumentsOf(1));
        self::assertCount(2, $records->turnPids(), 'The second turn is served by a second process.');
    }

    /**
     * Closing a thread that was never sent anything is a no-op.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function closingAnUnknownThreadDoesNothing(): void
    {
        $runner = $this->runner();

        $unknown = new ThreadId('slack:1700000012.000100');

        Coro::run(static function () use ($runner, $unknown): void {
            $runner->close($unknown);
        });

        self::assertSame([], $this->records()->starts());
    }

    /**
     * A child that stays inside its turn is terminated once the grace is up.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function terminatesAChildThatOverstaysTheGrace(): void
    {
        $this->useScenario(['turns' => ['1' => ['hang' => true]]]);
        $runner = $this->runner(grace: 0.3);
        $thread = new ThreadId('slack:1700000013.000100');
        $this->seedSession($thread);

        $elapsed = 0.0;
        Coro::run(static function () use ($runner, $thread, &$elapsed): void {
            // Not read: the fake never answers this turn.
            $runner->send($thread, 'hello');
            $started = microtime(true);
            $runner->close($thread);
            $elapsed = microtime(true) - $started;
        });

        self::assertGreaterThanOrEqual(0.3, $elapsed);
        self::assertLessThan(5.0, $elapsed, 'close() must return rather than wait for the child forever.');
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
        $thread = new ThreadId('slack:1700000014.000100');

        Coro::run(static function () use ($runner, $thread): void {
            $events = Events::collect($runner->send($thread, 'hello'));
            $runner->close($thread);

            self::assertCount(1, $events);
            self::assertInstanceOf(AgentError::class, Events::last($events));
        });
    }

    /**
     * The fallback is used once and then given up on.
     *
     * The binary here counts its own starts and refuses twice; from the third start it would
     * answer a turn, so an implementation that keeps falling back finishes with a boundary event
     * instead of hanging — a failed assertion rather than a test that never returns.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function stopsAfterOneFallback(): void
    {
        $counter = "{$this->home}/starts.log";
        $runner = $this->runner(binary: $this->countingBinary($counter));
        $thread = new ThreadId('slack:1700000015.000100');

        Coro::run(static function () use ($runner, $thread): void {
            $events = Events::collect($runner->send($thread, 'hello'));
            $runner->close($thread);

            self::assertCount(1, $events);
            self::assertInstanceOf(AgentError::class, Events::last($events));
        });

        self::assertSame(2, substr_count(self::read($counter), needle: "\n"), 'Exactly two attempts, then done.');
    }

    /**
     * @param list<string>|null $tools
     *
     * @return PersistentCliRunner pointed at the fake unless another binary is named
     */
    private function runner(
        ?string $binary = null,
        ?array $tools = null,
        float $grace = 2.0,
        ?FixedWorkingDirectory $directories = null,
    ): PersistentCliRunner {
        return new PersistentCliRunner(
            $directories ?? new FixedWorkingDirectory($this->cwd),
            new ClaudeCliSettings(
                binary: $binary ?? ClaudeBinary::fake(),
                allowedTools: $tools ?? ClaudeCliSettings::READ_ONLY_TOOLS,
                closeGraceSeconds: $grace,
            ),
        );
    }
}
