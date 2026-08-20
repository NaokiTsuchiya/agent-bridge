<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use NaokiTsuchiya\AgentBridge\Support\Coro;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Throwable;

use function microtime;

/**
 * That a wait taken here is one coroutine's and not the process'.
 *
 * The whole reason this adapter exists is what `sleep()` would have done instead: a Slack process
 * holds one connection and answers several turns on it, and a rate limit waited out with the event
 * loop stopped would stop reading frames as well — the connection would be dropped for not having
 * answered a ping while the process sat waiting to be allowed to speak. Two coroutines are the only
 * way to see that difference: with the loop stopped, nothing else would have run in the meantime.
 *
 * @internal
 */
final class CoroutineSleeperTest extends TestCase
{
    /** Long enough for the order below to mean something, short enough not to slow the suite. */
    private const float SECONDS = 0.02;

    /**
     * @throws Throwable whatever the coroutine raised
     */
    #[Test]
    public function letsTheRestOfTheProcessRunWhileItWaits(): void
    {
        $order = [];
        $started = microtime(as_float: true);

        Coro::run(static function () use (&$order): void {
            Coroutine::create(static function () use (&$order): void {
                new CoroutineSleeper()->sleep(self::SECONDS);
                $order[] = 'the one that waited';
            });
            Coroutine::create(static function () use (&$order): void {
                $order[] = 'everything else';
            });
        });

        self::assertSame(['everything else', 'the one that waited'], $order);
        self::assertGreaterThanOrEqual(self::SECONDS, microtime(as_float: true) - $started);
    }
}
