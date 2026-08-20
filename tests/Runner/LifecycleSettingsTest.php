<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Event\AgentError;
use NaokiTsuchiya\AgentBridge\Event\ClaudeCliEventParser;
use NaokiTsuchiya\AgentBridge\Support\ClaudeBinary;
use NaokiTsuchiya\AgentBridge\Support\Coro;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

/**
 * Propagation of lifecycle settings: process limits, idle timeout, and turn timeout are taken from
 * {@see LifecycleSettings} and dictate runner behavior.
 *
 * @internal
 */
final class LifecycleSettingsTest extends FakeCliRunnerTestCase
{
    /** @return string names this case's temp directories, so a stray one is easy to place */
    #[Override]
    protected function homePrefix(): string
    {
        return 'lifecycle-settings';
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
