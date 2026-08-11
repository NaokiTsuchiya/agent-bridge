<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Slack\EnvelopeLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The memory behind "hand each envelope on once", including what it forgets.
 *
 * @internal
 */
final class EnvelopeLogTest extends TestCase
{
    /**
     * The first sighting is what a caller acts on.
     *
     * @throws InvalidArgumentException
     */
    #[Test]
    public function answersTrueForAnIdItHasNotSeen(): void
    {
        self::assertTrue(new EnvelopeLog()->remember('ev-1'));
    }

    /**
     * The second sighting of the same id is the redelivery this class exists to catch.
     *
     * @throws InvalidArgumentException
     */
    #[Test]
    public function answersFalseTheSecondTime(): void
    {
        $log = new EnvelopeLog();
        $log->remember('ev-1');

        self::assertFalse($log->remember('ev-1'));
        self::assertTrue($log->remember('ev-2'), 'A different id is not a repeat.');
    }

    /**
     * Filling it exactly to the limit forgets nothing: the eviction is one id late, not one early.
     *
     * @throws InvalidArgumentException
     */
    #[Test]
    public function forgetsNothingAtExactlyTheCapacity(): void
    {
        $log = new EnvelopeLog(capacity: 3);

        foreach (['ev-1', 'ev-2', 'ev-3'] as $id) {
            $log->remember($id);
        }

        self::assertSame(3, $log->count());
        self::assertFalse($log->remember('ev-1'), 'The oldest is still known.');
        self::assertFalse($log->remember('ev-3'));
    }

    /**
     * One id over the limit drops the oldest, and only the oldest.
     *
     * @throws InvalidArgumentException
     */
    #[Test]
    public function forgetsTheOldestIdWhenItGoesOverTheCapacity(): void
    {
        $log = new EnvelopeLog(capacity: 3);

        foreach (['ev-1', 'ev-2', 'ev-3', 'ev-4'] as $id) {
            $log->remember($id);
        }

        self::assertSame(3, $log->count(), 'The memory is capped, not growing.');
        self::assertTrue($log->remember('ev-1'), 'The oldest was forgotten.');
        self::assertFalse($log->remember('ev-3'), 'The ones after it were not.');
        self::assertFalse($log->remember('ev-4'));
    }

    /**
     * Re-seeing an id must not re-insert it: that would make one repeated envelope evict all the rest.
     *
     * @throws InvalidArgumentException
     */
    #[Test]
    public function doesNotGrowWhenTheSameIdArrivesAgain(): void
    {
        $log = new EnvelopeLog(capacity: 2);
        $log->remember('ev-1');
        $log->remember('ev-2');
        $log->remember('ev-1');
        $log->remember('ev-3');

        self::assertSame(2, $log->count());
        self::assertTrue($log->remember('ev-1'), 'ev-1 kept its place in line and was evicted by ev-3.');
    }

    /**
     * The smallest useful memory still catches the redelivery that follows immediately.
     *
     * @throws InvalidArgumentException
     */
    #[Test]
    public function remembersTheLastIdWithACapacityOfOne(): void
    {
        $log = new EnvelopeLog(capacity: 1);
        $log->remember('ev-1');

        self::assertFalse($log->remember('ev-1'));
        self::assertTrue($log->remember('ev-2'));
        self::assertTrue($log->remember('ev-1'), 'Only one id fits, so ev-1 is gone.');
    }

    /**
     * A log that cannot hold anything would silently turn deduplication off.
     *
     * @throws InvalidArgumentException
     */
    #[DataProvider('uselessCapacities')]
    #[Test]
    public function refusesACapacityThatCannotHoldAnything(int $capacity): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EnvelopeLog($capacity);
    }

    /** @return iterable<string, array{int}> */
    public static function uselessCapacities(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }
}
