<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Runner;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Event\AgentError;
use NaokiTsuchiya\AgentBridge\Event\AgentEvent;
use NaokiTsuchiya\AgentBridge\Event\TurnCompleted;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliSettings;
use NaokiTsuchiya\AgentBridge\Runner\LifecycleSettings;
use NaokiTsuchiya\AgentBridge\Runner\PersistentCliRunner;
use NaokiTsuchiya\AgentBridge\Tests\Support\ChildProcesses;
use NaokiTsuchiya\AgentBridge\Tests\Support\ClaudeBinary;
use NaokiTsuchiya\AgentBridge\Tests\Support\Coro;
use NaokiTsuchiya\AgentBridge\Tests\Support\Json;
use NaokiTsuchiya\AgentBridge\Tests\Support\Parallel;
use NaokiTsuchiya\AgentBridge\Thread\ThreadDerivation;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

use function chmod;
use function file_put_contents;
use function in_array;
use function memory_get_usage;
use function microtime;
use function posix_kill;

/**
 * What the runner does with its children over time: one turn at a time, no more of them than
 * allowed, and none kept longer than it is worth.
 *
 * Every span here is a fraction of a second and comes from {@see LifecycleSettings}; nothing waits
 * out a real timeout. Whether two turns ran together is read from the fake's own record of when
 * each turn began and ended ({@see TurnSpan}), never from the wall clock this process reads. Every
 * thread's session is seeded, so a thread's turn is served by exactly one process.
 *
 * Each case ends with every thread closed, and that is not tidiness: the pool watches for idle
 * processes in a coroutine of its own, `Swoole\Coroutine\run()` waits for every coroutine, and the
 * watch only ends once the pool is empty. A case that left a process behind would sit there until
 * that process aged out.
 *
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class ProcessLifecycleTest extends FakeCliRunnerTestCase
{
    /** @return string names this case's temp directories, so a stray one is easy to place */
    #[Override]
    protected function homePrefix(): string
    {
        return 'lifecycle';
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
     * A process nobody is using is gone once its idle span has passed, with nothing else happening.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function reclaimsAProcessThatSatIdle(): void
    {
        $runner = $this->runner(new LifecycleSettings(idleSeconds: 0.2, turnSeconds: 5.0, maxProcesses: 4));
        $thread = $this->thread('slack:1800000004.000100');

        Coro::run(function () use ($runner, $thread): void {
            Events::collect($runner->send($thread, 'hello'));
            $pid = $this->pidFor($thread);
            self::assertTrue(self::alive($pid), 'The child was gone before the idle span had passed.');

            // Nothing is sent and nothing is closed here: the reclaiming has to happen by itself.
            Coroutine::sleep(0.6);

            self::assertSame(0, $runner->liveProcesses());
            self::assertFalse(self::alive($pid), 'The idle child is still running.');
        });
    }

    /**
     * The thread picks up where it left off, on a process started for the occasion.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function startsAgainAfterAnIdleReclaimAndKeepsTheContext(): void
    {
        $runner = $this->runner(new LifecycleSettings(idleSeconds: 0.2, turnSeconds: 5.0, maxProcesses: 4));
        $thread = $this->thread('slack:1800000005.000100');

        Coro::run(function () use ($runner, $thread): void {
            Events::collect($runner->send($thread, 'the word is cranberry'));
            $pid = $this->pidFor($thread);
            Coroutine::sleep(0.6);
            self::assertFalse(self::alive($pid), 'The idle child is still running.');

            $events = Events::collect($runner->send($thread, 'what was the word?'));
            $runner->close($thread);

            $last = Events::last($events);
            self::assertInstanceOf(TurnCompleted::class, $last, 'The turn after the reclaim did not finish.');
            self::assertTrue($last->success);
            self::assertStringContainsString('cranberry', Events::text($events));
        });

        self::assertCount(2, $this->records()->starts(), 'The reclaimed process must be replaced by a new one.');
    }

    /**
     * A turn that outlasts the idle span is not idle, and the process answering it is left alone.
     *
     * This is the distinction the whole issue turns on: a process that says nothing for a while is
     * either working or unused, and only the second kind may be reclaimed. The idle span is short
     * enough here that the watch meets the second turn while it is still being answered.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function keepsAProcessWhoseTurnOutlastsTheIdleSpan(): void
    {
        $this->useScenario(['turns' => ['2' => ['delay_ms' => 700]]]);
        $runner = $this->runner(new LifecycleSettings(idleSeconds: 0.2, turnSeconds: 10.0, maxProcesses: 4));
        $thread = $this->thread('slack:1800000019.000100');

        Coro::run(function () use ($runner, $thread): void {
            Events::collect($runner->send($thread, 'hello'));
            $slow = Events::collect($runner->send($thread, 'take your time'));

            $last = Events::last($slow);
            self::assertInstanceOf(TurnCompleted::class, $last, 'The long turn was cut short.');
            self::assertTrue($last->success);
            self::assertSame(1, $runner->liveProcesses(), 'The process was reclaimed while it was answering.');

            // Answered by the same process, which is what "was not reclaimed" looks like from
            // outside. A reclaimed one would be replaced quietly, and only the count of starts
            // would say so — the pid of a reclaimed child still answers `posix_kill` for as long
            // as it is defunct, so that would not.
            Events::collect($runner->send($thread, 'still there?'));
            self::assertCount(1, $this->records()->starts(), 'The turn was served by a second process.');

            $runner->close($thread);
        });
    }

    /**
     * A turn that begins while the watch is part-way through reclaiming is not reclaimed with it.
     *
     * The watch draws up a list of what has gone unused and then lets go of one entry at a time,
     * and letting go of one yields — long enough for a whole turn to begin on a thread further
     * down that list. What is asserted here is that such a turn survives: the list is a statement
     * about the past, and acting on it without asking again kills turns nobody could see start.
     *
     * The binary is one that ignores end of input and lingers, which is what holds the watch
     * inside a single reclaim long enough for the interleaving to be arranged rather than raced
     * for: the moment the count drops to one is the moment the watch is inside letting go of the
     * second-to-last process, with the last one already on its list.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function keepsATurnThatBeginsWhileTheWatchIsReclaiming(): void
    {
        $runner = $this->runner(
            new LifecycleSettings(idleSeconds: 0.6, turnSeconds: 20.0, maxProcesses: 4),
            binary: $this->lingeringBinary(),
            grace: 1.5,
        );
        $first = $this->thread('slack:1800000022.000100');
        $second = $this->thread('slack:1800000022.000200');
        $third = $this->thread('slack:1800000022.000300');

        Coro::run(static function () use ($runner, $first, $second, $third): void {
            Events::collect($runner->send($first, 'hello'));
            // Staggered so that the first deadline to pass is on its own, and the watch is still
            // inside letting that one go when the other two fall out of use.
            Coroutine::sleep(0.2);
            Events::collect($runner->send($second, 'hello'));
            Coroutine::sleep(0.2);
            Events::collect($runner->send($third, 'hello'));

            self::assertSame(3, $runner->liveProcesses());

            $spins = 0;
            $held = 3;
            while ($held > 1 && $spins < 1_000) {
                Coroutine::sleep(0.01);
                $held = $runner->liveProcesses();
                $spins++;
            }

            self::assertSame(1, $held, 'The watch never got as far as reclaiming.');

            // The watch is now inside letting go of the second process, and the third is the one
            // it walks to next.
            $events = Events::collect($runner->send($third, 'take your time'));
            $last = Events::last($events);
            self::assertSame(0, Events::tally($events, AgentError::class), 'The turn was cut short.');
            self::assertInstanceOf(TurnCompleted::class, $last, 'The turn was reclaimed out from under itself.');
            self::assertTrue($last->success);

            $runner->close($third);
        });
    }

    /**
     * A child that answers nothing is killed, and the turn ends with the error saying so.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function endsATurnNobodyAnswersWithAnError(): void
    {
        $this->useScenario(['turns' => ['1' => ['hang' => true]]]);
        $runner = $this->runner(new LifecycleSettings(idleSeconds: 30.0, turnSeconds: 0.3, maxProcesses: 4));
        $thread = $this->thread('slack:1800000006.000100');

        Coro::run(function () use ($runner, $thread): void {
            $events = Events::collect($runner->send($thread, 'hello'));
            $pid = $this->pidFor($thread);

            self::assertSame(0, Events::tally($events, TurnCompleted::class), 'The turn was never finished.');
            $last = Events::last($events);
            self::assertInstanceOf(AgentError::class, $last);
            self::assertStringContainsString('within 0.3 seconds', $last->message);
            self::assertSame(0, $runner->liveProcesses());
            self::assertFalse(self::alive($pid), 'The child that ran out of time is still running.');
        });

        $spans = $this->records()->spans();
        self::assertCount(1, $spans);
        self::assertNull(self::span($spans, 0)->endedAt, 'The fake was supposed to never answer this turn.');
    }

    /**
     * The caller gets the deadline back as events, and the thread is usable again afterwards.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function comesBackToTheCallerWhenATurnRunsOutOfTime(): void
    {
        $this->useScenario(['turns' => ['1' => ['hang' => true]]]);
        $runner = $this->runner(new LifecycleSettings(idleSeconds: 30.0, turnSeconds: 0.3, maxProcesses: 4));
        $thread = $this->thread('slack:1800000007.000100');

        Coro::run(static function () use ($runner, $thread): void {
            $started = microtime(true);
            Events::collect($runner->send($thread, 'hello'));
            self::assertLessThan(3.0, microtime(true) - $started, 'The caller was left waiting on the child.');

            // The second turn runs where a lock still held by the first one would show up as a
            // wait that never ends; the channel turns that into a failed assertion instead.
            $done = new Channel(1);
            Coroutine::create(static function () use ($runner, $thread, $done): void {
                Events::collect($runner->send($thread, 'anybody there?'));
                $done->push(true);
            });

            self::assertTrue($done->pop(3.0), 'The thread stayed locked by the turn that timed out.');
            $runner->close($thread);
        });
    }

    /**
     * A turn whose events nobody reads still ends when the thread is closed.
     *
     * The lock a turn takes has to come back by every way a turn can end, and this is the one no
     * generator can hand back by itself: the caller asked, never came for the answer, and closed
     * the thread. If the lock stayed, the next turn on this thread would wait for a turn nobody
     * is going to finish — so this asks for one and gives it a deadline.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function letsTheThreadGoOnAfterATurnNobodyRead(): void
    {
        $this->useScenario(['default' => ['delay_ms' => 100]]);
        $runner = $this->runner(new LifecycleSettings(idleSeconds: 30.0, turnSeconds: 10.0, maxProcesses: 4));
        $thread = $this->thread('slack:1800000020.000100');

        Coro::run(static function () use ($runner, $thread): void {
            // Deliberately not read: the turn is under way and nothing ever iterates it.
            $runner->send($thread, 'never mind the answer');
            $runner->close($thread);

            $done = new Channel(1);
            Coroutine::create(static function () use ($runner, $thread, $done): void {
                $events = Events::collect($runner->send($thread, 'hello again'));
                $done->push(Events::tally($events, TurnCompleted::class));
            });

            self::assertSame(1, $done->pop(5.0), 'The thread stayed locked by the turn nobody read.');
            $runner->close($thread);
        });
    }

    /**
     * A turn that takes its time, but less than it is given, is left alone.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function leavesATurnThatBeatsItsDeadlineAlone(): void
    {
        $this->useScenario(['default' => ['delay_ms' => 300]]);
        $runner = $this->runner(new LifecycleSettings(idleSeconds: 30.0, turnSeconds: 5.0, maxProcesses: 4));
        $thread = $this->thread('slack:1800000008.000100');

        Coro::run(static function () use ($runner, $thread): void {
            $events = Events::collect($runner->send($thread, 'hello'));
            $runner->close($thread);

            self::assertSame(0, Events::tally($events, AgentError::class));
            $last = Events::last($events);
            self::assertInstanceOf(TurnCompleted::class, $last);
            self::assertTrue($last->success);
        });
    }

    /**
     * A child that dies is reported as having died, not as having run out of time.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function tellsADeadChildApartFromASilentOne(): void
    {
        $this->useScenario(['turns' => ['1' => ['crash' => 3]]]);
        $runner = $this->runner(new LifecycleSettings(idleSeconds: 30.0, turnSeconds: 5.0, maxProcesses: 4));
        $thread = $this->thread('slack:1800000009.000100');

        Coro::run(static function () use ($runner, $thread): void {
            $events = Events::collect($runner->send($thread, 'hello'));
            $runner->close($thread);

            $last = Events::last($events);
            self::assertInstanceOf(AgentError::class, $last);
            self::assertStringContainsString('ended before finishing the turn', $last->message);
            self::assertStringNotContainsString('within', $last->message);
        });
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
     * How many processes there may be is what the settings say, not what the code decided.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function takesTheProcessLimitFromTheSettings(): void
    {
        foreach ([1, 3] as $limit) {
            $runner = $this->runner(new LifecycleSettings(idleSeconds: 30.0, turnSeconds: 5.0, maxProcesses: $limit));
            $first = $this->thread("slack:18000000{$limit}4.000100");
            $second = $this->thread("slack:18000000{$limit}4.000200");
            $third = $this->thread("slack:18000000{$limit}4.000300");

            Coro::run(function () use ($runner, $first, $second, $third, $limit): void {
                foreach ([$first, $second, $third] as $thread) {
                    Events::collect($runner->send($thread, 'hello'));
                    self::assertLessThanOrEqual($limit, $runner->liveProcesses());
                }

                self::assertSame($limit, $runner->liveProcesses(), "A limit of {$limit} was not what was applied.");
                $this->closeAll($runner, [$first, $second, $third]);
            });
        }
    }

    /**
     * How long a process may sit idle is the settings' answer, in both directions.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function takesTheIdleTimeoutFromTheSettings(): void
    {
        foreach ([0.2, 30.0] as $idle) {
            $reclaimed = $idle < 1.0;
            $runner = $this->runner(new LifecycleSettings(idleSeconds: $idle, turnSeconds: 5.0, maxProcesses: 4));
            $thread = $this->thread('slack:1800000015.0001' . ($reclaimed ? '10' : '20'));

            Coro::run(function () use ($runner, $thread, $reclaimed): void {
                Events::collect($runner->send($thread, 'hello'));
                $pid = $this->pidFor($thread);
                Coroutine::sleep(0.6);

                self::assertSame($reclaimed ? 0 : 1, $runner->liveProcesses());
                self::assertSame(!$reclaimed, self::alive($pid), 'The idle setting did not decide this.');
                $runner->close($thread);
            });
        }
    }

    /**
     * How long a turn may go unanswered is the settings' answer too.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function takesTheTurnTimeoutFromTheSettings(): void
    {
        $this->useScenario(['turns' => ['1' => ['hang' => true]]]);

        foreach ([0.3, 1.5] as $limit) {
            $short = $limit < 1.0;
            $runner = $this->runner(new LifecycleSettings(idleSeconds: 30.0, turnSeconds: $limit, maxProcesses: 4));
            $thread = $this->thread('slack:1800000016.0001' . ($short ? '10' : '20'));

            Coro::run(static function () use ($runner, $thread, $short): void {
                $done = new Channel(1);
                Coroutine::create(static function () use ($runner, $thread, $done): void {
                    $events = Events::collect($runner->send($thread, 'hello'));
                    $done->push(Events::tally($events, AgentError::class));
                });

                // Past the short deadline and well inside the long one.
                $errors = $done->pop(0.8);
                self::assertSame($short ? 1 : false, $errors, 'The turn timeout setting decided nothing.');

                if (!$short) {
                    self::assertSame(1, $done->pop(3.0), 'The longer deadline never arrived.');
                }

                $runner->close($thread);
            });
        }
    }

    /**
     * Two hundred turns later, the runner holds no more than it was allowed to, and no more
     * memory than it did after the first hundred.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function holdsNoMoreThanTheLimitOverHundredsOfTurns(): void
    {
        $limit = 2;
        $runner = $this->runner(new LifecycleSettings(idleSeconds: 30.0, turnSeconds: 10.0, maxProcesses: $limit));
        $first = $this->thread('slack:1800000017.000100');
        $second = $this->thread('slack:1800000017.000200');
        $third = $this->thread('slack:1800000017.000300');

        $warm = 0;
        $after = 0;
        Coro::run(function () use ($runner, $first, $second, $third, $limit, &$warm, &$after): void {
            $threads = [$first, $second, $third];
            for ($turn = 1; $turn <= 200; $turn++) {
                Events::collect($runner->send($threads[$turn % 3] ?? $first, "turn {$turn}"));
                self::assertLessThanOrEqual($limit, $runner->liveProcesses(), "Turn {$turn} held on to too many.");

                if ($turn !== 100) {
                    continue;
                }

                $warm = memory_get_usage(real_usage: true);
            }

            $after = memory_get_usage(real_usage: true);
            $this->closeAll($runner, $threads);
        });

        self::assertGreaterThan(0, $warm);
        self::assertLessThan(1_048_576, $after - $warm, 'The second hundred turns cost more than a megabyte.');
    }

    /**
     * Every way a child can end, and nothing of any of them left in the process table.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function leavesNoChildBehind(): void
    {
        $runner = $this->runner(new LifecycleSettings(idleSeconds: 0.3, turnSeconds: 0.4, maxProcesses: 2));
        $first = $this->thread('slack:1800000018.000100');
        $second = $this->thread('slack:1800000018.000200');
        $third = $this->thread('slack:1800000018.000300');

        Coro::run(function () use ($runner, $first, $second, $third): void {
            // Ordinary turns, the third of which reclaims the first thread's process at the limit.
            foreach ([$first, $second, $third] as $thread) {
                Events::collect($runner->send($thread, 'hello'));
            }

            // A process that dies in the middle of a turn. Each process reads the scenario as it
            // starts, so rewriting it here only reaches the ones started from now on.
            $this->useScenario(['turns' => ['1' => ['crash' => 2]]]);
            Events::collect($runner->send($first, 'die on me'));

            // A process that has to be killed because it answers nothing.
            $this->useScenario(['turns' => ['1' => ['hang' => true]]]);
            Events::collect($runner->send($first, 'say nothing'));

            // One closed by hand, and whatever is left swept up by the idle span.
            $this->useScenario([]);
            Events::collect($runner->send($second, 'hello again'));
            $runner->close($second);
            Coroutine::sleep(0.8);

            self::assertSame(0, $runner->liveProcesses());
        });

        self::assertSame([], ChildProcesses::all(), 'A child process outlived the runner.');
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
        return new PersistentCliRunner(
            new FixedWorkingDirectory($this->cwd),
            new ClaudeCliSettings(binary: $binary ?? ClaudeBinary::fake(), closeGraceSeconds: $grace),
            limits: $limits ?? new LifecycleSettings(),
        );
    }

    /**
     * A `claude`-shaped binary that answers turns and then refuses to take the hint.
     *
     * Two things the fake cannot do, both of them needed to hold the idle watch still: it ignores
     * end of input, so letting go of it takes the whole grace rather than a moment, and every turn
     * after the first outlasts that grace — so a turn reclaimed by mistake is killed in the middle
     * rather than quietly finishing anyway, which is what makes the mistake visible at all.
     *
     * @return string the path to the binary
     */
    private function lingeringBinary(): string
    {
        $path = "{$this->home}/lingering-claude";
        file_put_contents($path, <<<'SH'
            #!/bin/sh
            turn=0
            while IFS= read -r line; do
              turn=$((turn + 1))
              if [ "$turn" -gt 1 ]; then sleep 4; fi
              printf '{"type":"result","subtype":"success","is_error":false,"session_id":"x","result":"ok"}\n'
            done
            sleep 5
            SH);
        chmod($path, permissions: 0o755);

        return $path;
    }

    /**
     * @return ThreadId a thread whose session is already in place, so that `--resume` finds it
     *
     * @throws InvalidArgumentException
     */
    private function thread(string $id): ThreadId
    {
        $thread = new ThreadId($id);
        $this->seedSession($thread);

        return $thread;
    }

    /**
     * Closes every thread, so that the pool empties and the idle watch can end with it.
     *
     * @param list<ThreadId> $threads
     */
    private function closeAll(PersistentCliRunner $runner, array $threads): void
    {
        foreach ($threads as $thread) {
            $runner->close($thread);
        }
    }

    /** @return int the pid of the last process started for this thread's session */
    private function pidFor(ThreadId $thread): int
    {
        $session = ThreadDerivation::sessionId($thread);
        $records = $this->records();
        $pid = null;
        foreach ($records->starts() as $index => $start) {
            $mine = in_array($session, $records->argumentsOf($index), strict: true);
            if (!$mine) {
                continue;
            }

            $pid = Json::integer($start, 'pid');
        }

        self::assertIsInt($pid, "No process was recorded for {$thread->value}.");

        return $pid;
    }

    /**
     * @param list<TurnSpan> $spans
     *
     * @return TurnSpan the one at that position, asserted to be there
     */
    private static function span(array $spans, int $index): TurnSpan
    {
        $span = $spans[$index] ?? null;
        self::assertInstanceOf(TurnSpan::class, $span, "No turn was recorded at position {$index}.");

        return $span;
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
     * Raises what a coroutine caught, from where a throw is reported as a failure.
     *
     * @param list<Throwable> $failures
     *
     * @throws Throwable
     */
    private static function rethrow(array $failures): void
    {
        $failure = $failures[0] ?? null;
        if ($failure === null) {
            return;
        }

        throw $failure;
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

    /** @return bool whether the process is still there, defunct or otherwise */
    private static function alive(int $pid): bool
    {
        return posix_kill($pid, signal: 0);
    }
}
