<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Support\ChildProcesses;
use NaokiTsuchiya\AgentBridge\Support\Coro;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Runtime;
use Throwable;

use const SWOOLE_HOOK_PROC;
use const SWOOLE_HOOK_STREAM_FUNCTION;

/**
 * Verifies ProcessPool management, dead process pruning, and ProcessTable lookups.
 */
final class ProcessPoolTest extends TestCase
{
    /** Swoole's hook flags as they were before this class ran. */
    private static int $hookFlags = 0;

    /** {@inheritDoc} */
    #[Override]
    public static function setUpBeforeClass(): void
    {
        self::$hookFlags = Runtime::getHookFlags();
        Runtime::setHookFlags(self::$hookFlags | SWOOLE_HOOK_PROC | SWOOLE_HOOK_STREAM_FUNCTION);
    }

    /** {@inheritDoc} */
    #[Override]
    public static function tearDownAfterClass(): void
    {
        Runtime::setHookFlags(self::$hookFlags);
        if ((self::$hookFlags & SWOOLE_HOOK_PROC) !== 0) {
            Coroutine\run(static function (): void {});
        }
    }

    /**
     * A dead process is let go of during live() query rather than counted against limits.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function aDeadProcessIsLetGoOfRatherThanCounted(): void
    {
        $thread = new ThreadId('slack:pool.dead');

        Coro::run(static function () use ($thread): void {
            $pool = new ProcessPool(new LifecycleSettings(idleSeconds: 900.0, turnSeconds: 5.0, maxProcesses: 2), 1.0);

            $process = AgentProcess::start(['/bin/echo', 'finished'], '', HistoryStart::Beginning);
            self::assertNotNull($process);

            $admitted = $pool->admit($thread, static fn(): AgentProcess => $process);
            self::assertNotNull($admitted);
            self::assertSame(1, $pool->count());

            self::assertTrue($process->awaitExit(2.0));

            $live = $pool->live($thread);
            self::assertNull($live);
            self::assertSame(0, $pool->count());
            self::assertSame([], ChildProcesses::all());
        });
    }

    /**
     * A launch closure that returns null admits nothing into the pool.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function aLaunchThatStartsNothingAdmitsNothing(): void
    {
        $thread = new ThreadId('slack:pool.none');

        Coro::run(static function () use ($thread): void {
            $pool = new ProcessPool(new LifecycleSettings(idleSeconds: 900.0, turnSeconds: 5.0, maxProcesses: 2), 1.0);

            $admitted = $pool->admit($thread, static fn(): null => null);

            self::assertNull($admitted);
            self::assertSame(0, $pool->count());
            self::assertSame([], ChildProcesses::all());
        });
    }

    /**
     * Beginning a turn for a thread without a process does nothing and does not throw.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function beginningATurnForAThreadWithoutAProcessDoesNothing(): void
    {
        $thread = new ThreadId('slack:pool.unknown');

        Coro::run(static function () use ($thread): void {
            $pool = new ProcessPool(new LifecycleSettings(idleSeconds: 900.0, turnSeconds: 5.0, maxProcesses: 2), 1.0);

            $pool->beginTurn($thread);

            self::assertSame(0, $pool->count());
            self::assertSame([], ChildProcesses::all());
        });
    }

    /**
     * Discarding a thread without a process does nothing and does not throw.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function discardingAThreadWithoutAProcessDoesNothing(): void
    {
        $thread = new ThreadId('slack:pool.unknown');

        Coro::run(static function () use ($thread): void {
            $pool = new ProcessPool(new LifecycleSettings(idleSeconds: 900.0, turnSeconds: 5.0, maxProcesses: 2), 1.0);

            $pool->discard($thread);

            self::assertSame(0, $pool->count());
            self::assertSame([], ChildProcesses::all());
        });
    }

    /**
     * The process table returns null when taking an idle process for an unknown thread key.
     */
    #[Test]
    public function theTableHasNothingToTakeForAnUnknownThread(): void
    {
        $table = new ProcessTable();
        $taken = $table->takeIfIdle('unknown:key', 0.0);

        self::assertNull($taken);
        self::assertSame([], ChildProcesses::all());
    }
}
