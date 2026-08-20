<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Support\ChildProcesses;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

/**
 * Verifies Turn state transitions, TurnLocks cleanup on unheld locks, and TurnFailure events.
 */
final class TurnBookkeepingTest extends TestCase
{
    /**
     * Only the first finish() call on a turn returns true; subsequent calls return false.
     *
     * @throws InvalidArgumentException
     */
    #[Test]
    public function onlyTheFirstFinishOfATurnCounts(): void
    {
        $turn = new Turn(new ThreadId('slack:turn.1'), 1.0);

        self::assertFalse($turn->isFinished());
        self::assertTrue($turn->finish());
        self::assertTrue($turn->isFinished());
        self::assertFalse($turn->finish());
        self::assertSame([], ChildProcesses::all());
    }

    /**
     * Releasing a lock key that was never acquired does nothing.
     */
    #[Test]
    public function releasingALockNobodyTookDoesNothing(): void
    {
        $locks = new TurnLocks();
        $locks->release('slack:never.acquired');

        self::assertSame([], ChildProcesses::all());
    }

    /**
     * notStarted() failure message correctly includes the thread identifier.
     *
     * @throws InvalidArgumentException
     */
    #[Test]
    public function theMessageForAProcessThatNeverStartedNamesTheThread(): void
    {
        $thread = new ThreadId('slack:channel.msg');
        $error = TurnFailure::notStarted($thread);

        self::assertSame('The agent could not be started for "slack:channel.msg".', $error->message);
        self::assertSame([], ChildProcesses::all());
    }

    /**
     * only() produces a single event containing the given error.
     *
     * @throws InvalidArgumentException
     */
    #[Test]
    public function aFailureOnlyTurnIsThatOneEvent(): void
    {
        $thread = new ThreadId('slack:only.event');
        $error = TurnFailure::notStarted($thread);
        $events = iterator_to_array(TurnFailure::only($error));

        self::assertSame([$error], $events);
        self::assertSame([], ChildProcesses::all());
    }
}
