<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use NaokiTsuchiya\AgentBridge\Slack\SystemClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function microtime;
use function usleep;

/**
 * That the clock behind the streaming throttle is the machine's own, moving.
 *
 * What reads it decides whether enough time has passed to send the next fragment of a reply, so both
 * halves are load-bearing: a reading that came from somewhere other than now would throttle against
 * a time nobody is at, and one that never moved would hold every fragment back forever.
 *
 * @internal
 */
final class SystemClockTest extends TestCase
{
    /** Longer than the microsecond the clock is read in, so that the second reading has to differ. */
    private const int MICROSECONDS = 1000;

    /** Read between two readings of the same clock, and therefore between them. */
    #[Test]
    public function tellsTheTimeItIs(): void
    {
        $before = microtime(as_float: true);
        $now = new SystemClock()->now();
        $after = microtime(as_float: true);

        self::assertGreaterThanOrEqual($before, $now);
        self::assertLessThanOrEqual($after, $now);
    }

    /** And it moves, which is what the code reading it is waiting for. */
    #[Test]
    public function movesOnAsTimePasses(): void
    {
        $clock = new SystemClock();

        $first = $clock->now();
        usleep(self::MICROSECONDS);

        self::assertGreaterThan($first, $clock->now());
    }
}
