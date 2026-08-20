<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Event\AgentEvent;
use NaokiTsuchiya\AgentBridge\Event\ClaudeCliEventParser;
use NaokiTsuchiya\AgentBridge\Event\TurnCompleted;
use NaokiTsuchiya\AgentBridge\Support\ClaudeBinary;
use NaokiTsuchiya\AgentBridge\Support\Coro;
use NaokiTsuchiya\AgentBridge\Support\Parallel;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Swoole\Coroutine;
use Throwable;

/**
 * Process limits and LRU eviction: the runner holds no more than the limit, reclaims the least
 * recently used process first, leaves busy processes alone, and makes callers wait when every
 * process is busy.
 *
 * @internal
 */
final class ProcessLimitTest extends FakeCliRunnerTestCase
{
    /** @return string names this case's temp directories, so a stray one is easy to place */
    #[Override]
    protected function homePrefix(): string
    {
        return 'process-limit';
    }

    /**
     * The third thread costs the first one its process, and never adds a third.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function givesUpTheLeastRecentlyUsedProcessAtTheLimit(): void
    {
        $runner = $this->runner(new LifecycleSettings(idleSeconds: 30.0, turnSeconds: 5.0, maxProcesses: 2));
        $first = $this->thread('slack:1800000010.000100');
        $second = $this->thread('slack:1800000010.000200');
        $third = $this->thread('slack:1800000010.000300');

        Coro::run(function () use ($runner, $first, $second, $third): void {
            $pids = [];
            foreach ([$first, $second, $third] as $thread) {
                Events::collect($runner->send($thread, 'hello'));
                $pids[$thread->value] = $this->pidFor($thread);
                self::assertLessThanOrEqual(2, $runner->liveProcesses());
            }

            self::assertSame(2, $runner->liveProcesses());
            self::assertFalse(self::alive(self::recorded($pids, $first)), 'The process used longest ago was kept.');
            self::assertTrue(self::alive(self::recorded($pids, $second)));
            self::assertTrue(self::alive(self::recorded($pids, $third)));

            $this->closeAll($runner, [$first, $second, $third]);
        });
    }

    /**
     * Using a process again moves it out of the way of the next reclaim.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function ordersReclaimingByWhenAProcessWasLastUsed(): void
    {
        $runner = $this->runner(new LifecycleSettings(idleSeconds: 30.0, turnSeconds: 5.0, maxProcesses: 3));
        $first = $this->thread('slack:1800000011.000100');
        $second = $this->thread('slack:1800000011.000200');
        $third = $this->thread('slack:1800000011.000300');
        $fourth = $this->thread('slack:1800000011.000400');

        Coro::run(function () use ($runner, $first, $second, $third, $fourth): void {
            $pids = [];
            foreach ([$first, $second, $third] as $thread) {
                Events::collect($runner->send($thread, 'hello'));
                $pids[$thread->value] = $this->pidFor($thread);
            }

            // The oldest one is used again, which leaves the second one as the oldest.
            Events::collect($runner->send($first, 'and again'));

            Events::collect($runner->send($fourth, 'hello'));

            self::assertSame(3, $runner->liveProcesses());
            self::assertTrue(
                self::alive(self::recorded($pids, $first)),
                'The process used again was reclaimed anyway.',
            );
            self::assertFalse(self::alive(self::recorded($pids, $second)), 'The one used longest ago was kept.');
            self::assertTrue(self::alive(self::recorded($pids, $third)));

            $this->closeAll($runner, [$first, $second, $third, $fourth]);
        });
    }

    /**
     * A process in the middle of a turn is passed over, even when it is the oldest one there is.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function neverReclaimsAProcessThatIsAnsweringATurn(): void
    {
        // Every process answers its first turn at once and takes its time over the second, which
        // is how the thread that went first ends up being both the oldest and the busy one.
        $this->useScenario(['turns' => ['2' => ['delay_ms' => 600]]]);
        $runner = $this->runner(new LifecycleSettings(idleSeconds: 30.0, turnSeconds: 10.0, maxProcesses: 2));
        $first = $this->thread('slack:1800000012.000100');
        $second = $this->thread('slack:1800000012.000200');
        $third = $this->thread('slack:1800000012.000300');

        $failures = [];
        Coro::run(function () use ($runner, $first, $second, $third, &$failures): void {
            $pids = [];
            foreach ([$first, $second] as $thread) {
                Events::collect($runner->send($thread, 'hello'));
                $pids[$thread->value] = $this->pidFor($thread);
            }

            $slow = [];
            $failures = Parallel::run([
                static function () use ($runner, $first, &$slow): void {
                    $slow = Events::collect($runner->send($first, 'take your time'));
                },
                static function () use ($runner, $third): void {
                    // Long enough for the slow turn to be under way, short enough to be inside it.
                    Coroutine::sleep(0.2);
                    Events::collect($runner->send($third, 'hello'));
                },
            ]);

            $last = Events::last($slow);
            self::assertInstanceOf(TurnCompleted::class, $last, 'The busy turn did not survive the reclaim.');
            self::assertTrue($last->success);
            self::assertSame(2, $runner->liveProcesses());
            self::assertTrue(self::alive(self::recorded($pids, $first)), 'The busy process was reclaimed.');
            self::assertFalse(self::alive(self::recorded($pids, $second)), 'The idle process was kept instead.');

            $this->closeAll($runner, [$first, $second, $third]);
        });
        self::rethrow($failures);
    }

    /**
     * With every process busy, a new thread waits for a turn to end rather than adding one.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function waitsForATurnToEndWhenEveryProcessIsBusy(): void
    {
        $this->useScenario(['default' => ['delay_ms' => 300]]);
        $runner = $this->runner(new LifecycleSettings(idleSeconds: 30.0, turnSeconds: 10.0, maxProcesses: 1));
        $first = $this->thread('slack:1800000013.000100');
        $second = $this->thread('slack:1800000013.000200');

        $events = [];
        $failures = [];
        Coro::run(function () use ($runner, $first, $second, &$events, &$failures): void {
            $failures = Parallel::run([
                static function () use ($runner, $first, &$events): void {
                    $events['first'] = Events::collect($runner->send($first, 'hello'));
                },
                static function () use ($runner, $second, &$events): void {
                    Coroutine::sleep(0.05);
                    $events['second'] = Events::collect($runner->send($second, 'hello'));
                },
            ]);

            self::assertSame(1, $runner->liveProcesses());
            $this->closeAll($runner, [$first, $second]);
        });
        self::rethrow($failures);

        foreach (['first', 'second'] as $key) {
            $last = Events::last(self::turnOf($events, $key));
            self::assertInstanceOf(TurnCompleted::class, $last, "The {$key} turn did not finish.");
            self::assertTrue($last->success);
        }

        $spans = $this->records()->spans();
        self::assertCount(2, $spans);
        self::assertTrue(
            self::span($spans, 1)->startedAfter(self::span($spans, 0)),
            'The second thread did not wait for the first.',
        );
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
     * @param array<string, list<AgentEvent>> $turns
     *
     * @return list<AgentEvent> the events of that turn, asserted to have arrived
     */
    private static function turnOf(array $turns, string $key): array
    {
        $events = $turns[$key] ?? null;
        self::assertIsArray($events, "The {$key} turn produced nothing at all.");

        return $events;
    }

    /**
     * @param array<string, int> $pids the pid kept for each thread as its process was started
     *
     * @return int this thread's pid, asserted to have been kept
     */
    private static function recorded(array $pids, ThreadId $thread): int
    {
        $pid = $pids[$thread->value] ?? null;
        self::assertIsInt($pid, "No pid was kept for {$thread->value}.");

        return $pid;
    }
}
