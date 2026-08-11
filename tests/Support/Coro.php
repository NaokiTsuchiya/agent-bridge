<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Support;

use Closure;
use Throwable;

use function Swoole\Coroutine\run;

/**
 * Runs a test body inside a coroutine and lets its failures out.
 *
 * `Swoole\Coroutine\run()` does not propagate what the body throws: an exception raised inside
 * ends the whole process as a fatal error, and an outer `catch` never sees it. A failed PHPUnit
 * assertion is an exception, so without this a red test would take the runner down instead of
 * being reported. Caught on the inside, re-thrown on the outside.
 */
final class Coro
{
    /**
     * @param Closure(): void $body the test body, which may assert freely
     *
     * @throws Throwable whatever the body raised
     */
    public static function run(Closure $body): void
    {
        $thrown = null;
        run(static function () use ($body, &$thrown): void {
            try {
                $body();
            } catch (Throwable $error) {
                $thrown = $error;
            }
        });

        if ($thrown !== null) {
            throw $thrown;
        }
    }
}
