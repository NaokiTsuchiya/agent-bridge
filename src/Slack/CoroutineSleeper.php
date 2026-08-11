<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Override;
use Swoole\Coroutine;

/**
 * Waits by yielding the coroutine, leaving the rest of the process running.
 *
 * @api
 */
final class CoroutineSleeper implements SleeperInterface
{
    /** `sleep()` would block the whole event loop; `Coroutine::sleep()` only parks this coroutine. */
    #[Override]
    public function sleep(float $seconds): void
    {
        Coroutine::sleep($seconds);
    }
}
