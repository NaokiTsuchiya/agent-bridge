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
use Throwable;

use function chmod;
use function file_put_contents;

/**
 * Idle process reclamation: unused processes are reclaimed when their idle span passes, but busy
 * turns and turns starting during a reclaim are left alone.
 *
 * @internal
 */
final class IdleReclaimTest extends FakeCliRunnerTestCase
{
    /** @return string names this case's temp directories, so a stray one is easy to place */
    #[Override]
    protected function homePrefix(): string
    {
        return 'idle-reclaim';
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
}
