<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

/**
 * The wait between connection attempts: how long ({@see Backoff}) and the waiting itself.
 *
 * The two are kept apart because one is arithmetic and the other is time, and only the arithmetic
 * can be asserted on; they are paired here so that the loop asks for a wait and gets one.
 *
 * @api
 */
final class ReconnectDelay
{
    /** @param SleeperInterface $sleeper what actually gives up the time; a test records it instead */
    public function __construct(
        private Backoff $backoff,
        private SleeperInterface $sleeper,
    ) {}

    /** Waits out the delay for the given attempt, counting from 1. */
    public function waitBefore(int $attempt): void
    {
        $this->sleeper->sleep($this->backoff->delay($attempt));
    }
}
