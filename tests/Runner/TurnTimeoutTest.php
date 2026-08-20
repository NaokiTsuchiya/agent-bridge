<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Event\AgentError;
use NaokiTsuchiya\AgentBridge\Event\ClaudeCliEventParser;
use NaokiTsuchiya\AgentBridge\Event\TurnCompleted;
use NaokiTsuchiya\AgentBridge\Support\ClaudeBinary;
use NaokiTsuchiya\AgentBridge\Support\Coro;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

use function microtime;

/**
 * Turn timeout, dead child detection, and unread turn cleanup.
 *
 * @internal
 */
final class TurnTimeoutTest extends FakeCliRunnerTestCase
{
    /** @return string names this case's temp directories, so a stray one is easy to place */
    #[Override]
    protected function homePrefix(): string
    {
        return 'turn-timeout';
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
}
