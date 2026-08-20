<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The delay ladder, pinned by feeding it a fraction instead of real randomness.
 *
 * @mago-expect lint:too-many-methods
 *
 * @internal
 */
final class BackoffTest extends TestCase
{
    /** The first attempt waits the base delay, and each one after it waits twice the last. */
    #[DataProvider('rungs')]
    #[Test]
    public function doublesTheDelayWithEachAttempt(int $attempt, float $expected): void
    {
        self::assertSame($expected, self::backoff()->delay($attempt));
    }

    /** @return iterable<string, array{int, float}> */
    public static function rungs(): iterable
    {
        yield 'first' => [1, 2.0];
        yield 'second' => [2, 4.0];
        yield 'third' => [3, 8.0];
        yield 'fourth' => [4, 16.0];
    }

    /** The ladder stops at the ceiling and stays there, however long the outage runs. */
    #[DataProvider('attemptsAtOrBeyondTheCeiling')]
    #[Test]
    public function stopsAtTheCeiling(int $attempt): void
    {
        self::assertSame(32.0, self::backoff()->delay($attempt));
    }

    /** @return iterable<string, array{int}> */
    public static function attemptsAtOrBeyondTheCeiling(): iterable
    {
        yield 'exactly at it' => [5];
        yield 'one past it' => [6];
        // `2 ** 999` is INF; the ceiling has to survive that rather than propagate it.
        yield 'far past it' => [1000];
    }

    /** An attempt below the first is still an attempt; it must not compute a fraction of the base. */
    #[DataProvider('attemptsBeforeTheFirst')]
    #[Test]
    public function treatsAnAttemptBelowOneAsTheFirst(int $attempt): void
    {
        self::assertSame(2.0, self::backoff()->delay($attempt));
    }

    /** @return iterable<string, array{int}> */
    public static function attemptsBeforeTheFirst(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
    }

    /** The jitter is taken off the delay, so it can shorten a wait but never lengthen it past the ceiling. */
    #[DataProvider('jitterFractions')]
    #[Test]
    public function subtractsTheJitterFromTheDelay(float $fraction, float $expected): void
    {
        $backoff = new Backoff(new FixedRandomSource($fraction), base: 2.0, max: 32.0, jitterRatio: 0.5);

        self::assertSame($expected, $backoff->delay(1));
    }

    /** @return iterable<string, array{float, float}> */
    public static function jitterFractions(): iterable
    {
        yield 'nothing taken off' => [0.0, 2.0];
        yield 'half the ratio' => [0.5, 1.5];
        yield 'the whole ratio' => [1.0, 1.0];
    }

    /** Without a jitter ratio the delay is the same whatever the randomness says. */
    #[Test]
    public function ignoresTheRandomSourceWhenThereIsNoJitter(): void
    {
        $backoff = new Backoff(new FixedRandomSource(0.99), base: 2.0, max: 32.0, jitterRatio: 0.0);

        self::assertSame(4.0, $backoff->delay(2));
    }

    /** Whatever the ladder and the jitter do, the wait stays inside the bounds the caller set. */
    #[Test]
    public function staysWithinTheCeilingAndAboveZero(): void
    {
        $backoff = new Backoff(new FixedRandomSource(1.0), base: 2.0, max: 32.0, jitterRatio: 0.5);

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $delay = $backoff->delay($attempt);

            self::assertGreaterThan(0.0, $delay, "Attempt {$attempt} must still wait.");
            self::assertLessThanOrEqual(32.0, $delay, "Attempt {$attempt} must not exceed the ceiling.");
        }
    }

    /** The subject with the jitter switched off, so that the ladder itself is what is asserted on. */
    private static function backoff(): Backoff
    {
        return new Backoff(new FixedRandomSource(), base: 2.0, max: 32.0);
    }
}
