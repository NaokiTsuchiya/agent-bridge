<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Event\AgentError;
use NaokiTsuchiya\AgentBridge\Event\ClaudeCliEventParser;
use NaokiTsuchiya\AgentBridge\Support\ChildProcesses;
use NaokiTsuchiya\AgentBridge\Support\Coro;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Runtime;
use Throwable;

use function iterator_to_array;

use const SWOOLE_HOOK_PROC;
use const SWOOLE_HOOK_STREAM_FUNCTION;

/**
 * Verifies TurnEvents event streaming and fallback when a replacement process fails to start.
 */
final class TurnEventsTest extends TestCase
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
     * When a continuing process yields no events and restart cannot start a replacement, the turn ends in failure.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function aTurnWhoseReplacementCannotStartEndsAsAFailure(): void
    {
        $thread = new ThreadId('slack:turn.restart.fail');

        Coro::run(static function () use ($thread): void {
            $pool = new ProcessPool(new LifecycleSettings(idleSeconds: 900.0, turnSeconds: 5.0, maxProcesses: 2), 1.0);
            $turn = new Turn($thread, 2.0);

            $shortProcess = AgentProcess::start(['/usr/bin/true'], '', HistoryStart::Continuing);
            self::assertNotNull($shortProcess);

            $endCalls = [];
            $events = new TurnEvents(
                new ClaudeCliEventParser(),
                $pool,
                $turn,
                static fn(): null => null,
                static function (?AgentProcess $answered) use (&$endCalls): void {
                    $endCalls[] = $answered;
                },
            );

            $collected = iterator_to_array($events->all($shortProcess));
            $shortProcess->release();

            self::assertCount(1, $collected);
            $first = $collected[0] ?? null;
            self::assertInstanceOf(AgentError::class, $first);
            self::assertSame('The agent could not be started for "slack:turn.restart.fail".', $first->message);
            self::assertSame([null], $endCalls);
            self::assertSame([], ChildProcesses::all());
        });
    }
}
