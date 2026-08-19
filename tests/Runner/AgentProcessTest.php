<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Runner;

use NaokiTsuchiya\AgentBridge\Runner\AgentProcess;
use NaokiTsuchiya\AgentBridge\Runner\HistoryStart;
use NaokiTsuchiya\AgentBridge\Tests\Support\ChildProcesses;
use NaokiTsuchiya\AgentBridge\Tests\Support\Warnings;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Runtime;
use Throwable;

use function microtime;
use function str_contains;

use const SWOOLE_HOOK_PROC;
use const SWOOLE_HOOK_STREAM_FUNCTION;

/**
 * Verifies AgentProcess behavior on unhooked plain execution environments.
 */
final class AgentProcessTest extends TestCase
{
    /** Swoole's hook flags as they were before this class ran. */
    private static int $hookFlags = 0;

    /** {@inheritDoc} */
    #[Override]
    public static function setUpBeforeClass(): void
    {
        self::$hookFlags = Runtime::getHookFlags();
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

    /** {@inheritDoc} */
    #[Override]
    protected function setUp(): void
    {
        Runtime::setHookFlags(self::$hookFlags & ~(SWOOLE_HOOK_PROC | SWOOLE_HOOK_STREAM_FUNCTION));
    }

    /**
     * A nonexistent binary cannot be started and returns null.
     *
     * @throws Throwable
     */
    #[Test]
    public function aProcessThatCannotBeStartedIsNoProcess(): void
    {
        $process = null;
        $warnings = Warnings::captured(static function () use (&$process): void {
            $process = AgentProcess::start(['/nonexistent/bin/agent-bridge-xyz'], '', HistoryStart::Beginning);
        });

        self::assertNull($process);
        self::assertCount(1, $warnings);
        self::assertTrue(str_contains($warnings[0] ?? '', 'proc_open'));
        self::assertSame([], ChildProcesses::all());
    }

    /**
     * Writing after the input stream has been closed does nothing.
     *
     * @throws Throwable
     */
    #[Test]
    public function writingAfterTheEndOfInputDoesNothing(): void
    {
        $process = AgentProcess::start(['/bin/cat'], '', HistoryStart::Beginning);
        self::assertNotNull($process);

        $process->closeInput();

        $warnings = Warnings::captured(static function () use ($process): void {
            $process->write("ignored line\n");
        });

        self::assertSame([], $warnings);
        self::assertTrue($process->awaitExit(2.0));
        $process->release();
        self::assertSame([], ChildProcesses::all());
    }

    /**
     * Waiting outside a coroutine executes usleep and gives up at the deadline.
     */
    #[Test]
    public function waitingOutsideACoroutineGivesUpAtTheDeadline(): void
    {
        $process = AgentProcess::start(['/bin/cat'], '', HistoryStart::Beginning);
        self::assertNotNull($process);

        $started = microtime(true);
        $exited = $process->awaitExit(0.02);
        $elapsed = microtime(true) - $started;

        self::assertFalse($exited);
        self::assertGreaterThanOrEqual(0.02, $elapsed);

        $process->closeInput();
        self::assertTrue($process->awaitExit(2.0));
        $process->release();
        self::assertSame([], ChildProcesses::all());
    }

    /**
     * Recording an emission changes the emission state from false to true.
     */
    #[Test]
    public function recordingEmissionChangesEmissionState(): void
    {
        $process = AgentProcess::start(['/bin/cat'], '', HistoryStart::Beginning);
        self::assertNotNull($process);

        self::assertFalse($process->hasEmitted());
        $process->recordEmission();
        self::assertTrue($process->hasEmitted());

        $process->closeInput();
        self::assertTrue($process->awaitExit(2.0));
        $process->release();
        self::assertSame([], ChildProcesses::all());
    }

    /**
     * Beginning and ending turns manages busy flag and advances last-used timestamp.
     */
    #[Test]
    public function turnLifecycleUpdatesBusyAndLastUsedAt(): void
    {
        $beforeStart = microtime(true);
        $process = AgentProcess::start(['/bin/cat'], '', HistoryStart::Beginning);
        self::assertNotNull($process);

        self::assertFalse($process->isBusy());
        self::assertGreaterThanOrEqual($beforeStart, $process->lastUsedAt());

        $process->beginTurn();
        self::assertTrue($process->isBusy());

        $beforeEnd = microtime(true);
        $process->endTurn();
        self::assertFalse($process->isBusy());
        self::assertGreaterThanOrEqual($beforeEnd, $process->lastUsedAt());

        $process->closeInput();
        self::assertTrue($process->awaitExit(2.0));
        $process->release();
        self::assertSame([], ChildProcesses::all());
    }
}
