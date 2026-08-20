<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Event\ClaudeCliEventParser;
use NaokiTsuchiya\AgentBridge\Support\ChildProcesses;
use NaokiTsuchiya\AgentBridge\Support\ClaudeBinary;
use NaokiTsuchiya\AgentBridge\Support\Coro;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Swoole\Coroutine;
use Throwable;

use function memory_get_usage;

/**
 * Endurance and child process cleanup: process counts and memory remain bounded over hundreds of
 * turns, and all children (crashed, hung, closed, or reclaimed) are reaped.
 *
 * @internal
 */
final class ProcessEnduranceTest extends FakeCliRunnerTestCase
{
    /** @return string names this case's temp directories, so a stray one is easy to place */
    #[Override]
    protected function homePrefix(): string
    {
        return 'process-endurance';
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
