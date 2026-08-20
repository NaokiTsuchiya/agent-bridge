<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

/**
 * The range the backoff jitter is built on, and that it is a range at all.
 *
 * Both halves matter to what reads this: a jitter is multiplied into a delay, so a draw that reached
 * 1.0 would hand out a full extra interval, and a source that always answered the same would put
 * every retry of every process on the same schedule — which is the thundering herd the jitter is
 * there to break up.
 *
 * @internal
 */
final class MtRandomSourceTest extends TestCase
{
    /** Enough draws for the bounds to be worth asserting, few enough to stay instant. */
    private const int DRAWS = 1000;

    /** A fraction of one, never one: `1.0` would be a whole interval handed out as jitter. */
    #[Test]
    public function drawsAFractionOfOne(): void
    {
        $source = new MtRandomSource();

        for ($draw = 0; $draw < self::DRAWS; $draw++) {
            $fraction = $source->fraction();

            self::assertGreaterThanOrEqual(0.0, $fraction);
            self::assertLessThan(1.0, $fraction);
        }
    }

    /** And a different one each time, which is the only thing a jitter is for. */
    #[Test]
    public function drawsSomethingElseTheNextTime(): void
    {
        $source = new MtRandomSource();
        $seen = [];

        for ($draw = 0; $draw < self::DRAWS; $draw++) {
            $seen[(string) $source->fraction()] = true;
        }

        self::assertGreaterThan(1, count($seen), 'Every draw was the same value.');
    }
}
