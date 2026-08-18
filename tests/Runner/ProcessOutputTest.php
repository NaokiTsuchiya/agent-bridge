<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Runner;

use NaokiTsuchiya\AgentBridge\Runner\ProcessOutput;
use NaokiTsuchiya\AgentBridge\Tests\Support\ChildProcesses;
use NaokiTsuchiya\AgentBridge\Tests\Support\Coro;
use NaokiTsuchiya\AgentBridge\Tests\Support\Warnings;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Runtime;
use Throwable;

use function fclose;
use function fopen;
use function fwrite;
use function stream_socket_pair;

use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use const SWOOLE_HOOK_PROC;
use const SWOOLE_HOOK_STREAM_FUNCTION;

/**
 * Verifies ProcessOutput stream consumption, deadline budgets, and stream termination.
 */
final class ProcessOutputTest extends TestCase
{
    /** Swoole's hook flags as they were before this class ran. */
    private static int $hookFlags = 0;

    /** {@inheritDoc} */
    #[Override]
    public static function setUpBeforeClass(): void
    {
        self::$hookFlags = Runtime::getHookFlags();
        Runtime::setHookFlags(self::$hookFlags | SWOOLE_HOOK_STREAM_FUNCTION);
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
     * An output instance with no streams left is immediately marked ended.
     *
     * @throws Throwable
     */
    #[Test]
    public function outputWithNoStreamsLeftHasEnded(): void
    {
        Coro::run(static function (): void {
            $output = new ProcessOutput(null, null);

            self::assertNull($output->nextLine(0.1));
            self::assertTrue($output->ended());
            self::assertSame([], ChildProcesses::all());
        });
    }

    /**
     * A stream that cannot be selected by stream_select marks the output as ended.
     *
     * @throws Throwable
     */
    #[Test]
    public function aStreamThatCannotBeSelectedEndsTheOutput(): void
    {
        Coro::run(static function (): void {
            $memory = fopen('php://memory', mode: 'r+');
            self::assertIsResource($memory);

            $output = new ProcessOutput($memory, null);
            $line = null;
            $warnings = Warnings::captured(static function () use ($output, &$line): void {
                $line = $output->nextLine(0.1);
            });

            self::assertNull($line);
            self::assertTrue($output->ended());
            self::assertNotEmpty($warnings);

            $output->close();
            self::assertSame([], ChildProcesses::all());
        });
    }

    /**
     * Waiting without a deadline waits indefinitely until a line arrives.
     *
     * @throws Throwable
     */
    #[Test]
    public function waitingWithoutADeadlineWaitsForTheLine(): void
    {
        Coro::run(static function (): void {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, protocol: 0);
            self::assertIsArray($pair);
            [$read, $write] = $pair;

            fwrite($write, data: "one whole line\n");
            fclose($write);

            $output = new ProcessOutput($read, null);
            $line = $output->nextLine();

            self::assertSame('one whole line', $line);
            $output->close();
            self::assertSame([], ChildProcesses::all());
        });
    }

    /**
     * A deadline that has already passed does not wait and leaves ended as false.
     *
     * @throws Throwable
     */
    #[Test]
    public function aDeadlineAlreadyPassedDoesNotWait(): void
    {
        Coro::run(static function (): void {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, protocol: 0);
            self::assertIsArray($pair);
            [$read, $write] = $pair;

            $output = new ProcessOutput($read, null);
            $line = $output->nextLine(0.0);

            self::assertNull($line);
            self::assertFalse($output->ended());

            $output->close();
            fclose($write);
            self::assertSame([], ChildProcesses::all());
        });
    }

    /**
     * A stream that is null is skipped by open() without preventing reading from available streams.
     *
     * @throws Throwable
     */
    #[Test]
    public function aStreamThatIsGoneIsSkippedNotSelected(): void
    {
        Coro::run(static function (): void {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, protocol: 0);
            self::assertIsArray($pair);
            [$read, $write] = $pair;

            fwrite($write, data: "active stream line\n");
            fclose($write);

            // One stream is null, which deterministically exercises the continue in open()
            $output = new ProcessOutput($read, null);
            $line = $output->nextLine(0.5);

            self::assertSame('active stream line', $line);
            $output->close();
            self::assertSame([], ChildProcesses::all());
        });
    }
}
