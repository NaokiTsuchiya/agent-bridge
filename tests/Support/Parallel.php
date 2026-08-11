<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Support;

use Closure;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

use function count;

/**
 * Runs several bodies at the same time and brings their failures back to the caller.
 *
 * A failed assertion inside a coroutine started with `Coroutine::create` is a fatal error, not a
 * reported failure: nothing outside that coroutine ever sees it. Each body's throw is therefore
 * caught where it happens and handed back, for the test to raise from where a throw is reported
 * as a failure — outside `Swoole\Coroutine\run()`.
 */
final class Parallel
{
    /**
     * @param list<Closure(): void> $bodies  run concurrently, each in its own coroutine
     * @param float                 $timeout how long to wait for all of them, in seconds
     *
     * @return list<Throwable> what the bodies raised, empty when none of them did
     */
    public static function run(array $bodies, float $timeout = 20.0): array
    {
        $done = new Channel(count($bodies));
        $failures = [];
        foreach ($bodies as $body) {
            Coroutine::create(static function () use ($body, $done, &$failures): void {
                try {
                    $body();
                } catch (Throwable $error) {
                    $failures[] = $error;
                }

                $done->push(true);
            });
        }

        $remaining = count($bodies);
        while ($remaining > 0) {
            $done->pop($timeout);
            $remaining--;
        }

        return $failures;
    }
}
