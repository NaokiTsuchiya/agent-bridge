<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Event\ClaudeCliEventParser;
use NaokiTsuchiya\AgentBridge\Support\ClaudeBinary;
use NaokiTsuchiya\AgentBridge\Support\Coro;
use NaokiTsuchiya\AgentBridge\Support\Parallel;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Swoole\Coroutine;
use Throwable;

/**
 * Multiple threads and multiple turns on the same thread: turns on one thread are serialized, while
 * different threads run beside each other.
 *
 * @internal
 */
final class ThreadConcurrencyTest extends FakeCliRunnerTestCase
{
    /** @return string names this case's temp directories, so a stray one is easy to place */
    #[Override]
    protected function homePrefix(): string
    {
        return 'thread-concurrency';
    }

    /**
     * Two turns asked for at once on one thread are answered one after the other.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function answersOneTurnAtATimeOnOneThread(): void
    {
        $this->useScenario(['default' => ['delay_ms' => 150]]);
        $runner = $this->runner();
        $thread = $this->thread('slack:1800000001.000100');

        $failures = [];
        Coro::run(static function () use ($runner, $thread, &$failures): void {
            $failures = Parallel::run([
                static function () use ($runner, $thread): void {
                    Events::collect($runner->send($thread, 'first question'));
                },
                static function () use ($runner, $thread): void {
                    Events::collect($runner->send($thread, 'second question'));
                },
            ]);
            $runner->close($thread);
        });
        self::rethrow($failures);

        $spans = $this->records()->spans();
        self::assertCount(2, $spans);
        self::assertTrue(
            self::span($spans, 1)->startedAfter(self::span($spans, 0)),
            'The second turn began before the first one had been answered.',
        );
    }

    /**
     * A thread that has already made somebody wait keeps its lock for the turn after that.
     *
     * The third turn arrives once the first two have been queued, i.e. after a release that had
     * a waiter parked on it. A lock the runner forgot at that moment would be replaced by a new
     * one for the third turn, which would then run beside the second — two turns in one worktree,
     * and nothing in the events to say so. Hence the third interval is checked, not just the
     * second: with only two turns, a lock that is forgotten too eagerly still looks correct.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function keepsSerializingAThreadAfterAWaiterHasBeenLetThrough(): void
    {
        $this->useScenario(['default' => ['delay_ms' => 300]]);
        $runner = $this->runner();
        $thread = $this->thread('slack:1800000021.000100');

        $failures = [];
        Coro::run(static function () use ($runner, $thread, &$failures): void {
            $failures = Parallel::run([
                static function () use ($runner, $thread): void {
                    Events::collect($runner->send($thread, 'first question'));
                },
                static function () use ($runner, $thread): void {
                    Events::collect($runner->send($thread, 'second question'));
                },
                static function () use ($runner, $thread): void {
                    // Inside the second turn, and after the first one handed the lock over.
                    Coroutine::sleep(0.45);
                    Events::collect($runner->send($thread, 'third question'));
                },
            ]);
            $runner->close($thread);
        });
        self::rethrow($failures);

        $spans = $this->records()->spans();
        self::assertCount(3, $spans, 'Three turns must be three intervals; overlapping ones are not.');
        self::assertTrue(self::span($spans, 1)->startedAfter(self::span($spans, 0)));
        self::assertTrue(
            self::span($spans, 2)->startedAfter(self::span($spans, 1)),
            'The third turn began before the second one had been answered.',
        );
    }

    /**
     * Two threads are answered at the same time, which is the point of locking per thread.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function answersTwoThreadsAtTheSameTime(): void
    {
        $this->useScenario(['default' => ['delay_ms' => 250]]);
        $runner = $this->runner();
        $first = $this->thread('slack:1800000002.000100');
        $second = $this->thread('slack:1800000002.000200');

        $failures = [];
        Coro::run(static function () use ($runner, $first, $second, &$failures): void {
            $failures = Parallel::run([
                static function () use ($runner, $first): void {
                    Events::collect($runner->send($first, 'one'));
                    $runner->close($first);
                },
                static function () use ($runner, $second): void {
                    Events::collect($runner->send($second, 'two'));
                    $runner->close($second);
                },
            ]);
        });
        self::rethrow($failures);

        $spans = $this->records()->spans();
        self::assertCount(2, $spans);
        self::assertTrue(
            self::span($spans, 0)->overlaps(self::span($spans, 1)),
            'The two turns did not run at the same time.',
        );
    }

    /**
     * Threads running together answer out of their own history and not each other's.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function keepsEachThreadsContextWhileTheyRunTogether(): void
    {
        $this->useScenario(['default' => ['delay_ms' => 120]]);
        $runner = $this->runner();
        $first = $this->thread('slack:1800000003.000100');
        $second = $this->thread('slack:1800000003.000200');

        $replies = [];
        $failures = [];
        Coro::run(static function () use ($runner, $first, $second, &$replies, &$failures): void {
            $ask = static function (ThreadId $thread, string $word) use ($runner, &$replies): void {
                Events::collect($runner->send($thread, "the word is {$word}"));
                $replies[$word] = Events::text(Events::collect($runner->send($thread, 'what was the word?')));
                $runner->close($thread);
            };

            $failures = Parallel::run([
                static function () use ($ask, $first): void {
                    $ask($first, word: 'apricot');
                },
                static function () use ($ask, $second): void {
                    $ask($second, word: 'blueberry');
                },
            ]);
        });
        self::rethrow($failures);

        self::assertStringContainsString('apricot', self::reply($replies, 'apricot'));
        self::assertStringNotContainsString('blueberry', self::reply($replies, 'apricot'));
        self::assertStringContainsString('blueberry', self::reply($replies, 'blueberry'));
        self::assertStringNotContainsString('apricot', self::reply($replies, 'blueberry'));
    }

    /**
     * @param string|null $binary something other than the fake, for a case that needs a child
     *                            the fake cannot be made into
     * @param float       $grace  how long a child is given to end by itself when it is let go of
     *
     * @return PersistentCliRunner pointed at the fake, with the limits under test
     */
    private function runner(
        ?LifecycleSettings $limits = null,
        ?string $binary = null,
        float $grace = 2.0,
    ): PersistentCliRunner {
        $actualLimits = $limits ?? new LifecycleSettings();
        $settings = new ClaudeCliSettings(binary: $binary ?? ClaudeBinary::fake(), closeGraceSeconds: $grace);

        return new PersistentCliRunner(
            new ProcessRecipe(new FixedWorkingDirectory($this->cwd), new ClaudeCliCommand($settings)),
            new ClaudeCliEventParser(),
            new TurnLocks(),
            new ProcessPool($actualLimits, $settings->closeGraceSeconds),
            $actualLimits->turnSeconds,
        );
    }

    /**
     * @param array<string, string> $replies
     *
     * @return string what that thread was told, asserted to have arrived
     */
    private static function reply(array $replies, string $word): string
    {
        $reply = $replies[$word] ?? null;
        self::assertIsString($reply, "The thread asked about {$word} got no reply.");

        return $reply;
    }
}
