<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use Swoole\Coroutine\Channel;

/**
 * One mutex per thread, so that a thread answers one turn at a time.
 *
 * A capacity-1 channel is the mutex: pushing takes it and popping gives it back, and a second
 * pusher parks until the first pops. Threads hold separate channels and never wait on each other,
 * which is the whole point — a thread's worktree is its own, so serializing the thread is what
 * serializes the worktree, and nothing else has to be locked.
 *
 * Only usable from inside a coroutine: a channel push outside one has nothing to park.
 *
 * @api
 */
final class TurnLocks
{
    /** @var array<string, Channel> the mutex of each thread that has one, by thread id */
    private array $locks = [];

    /** Parks until the thread's previous turn has given the lock back. */
    public function acquire(string $key): void
    {
        $lock = $this->locks[$key] ?? new Channel(1);
        $this->locks[$key] = $lock;
        $lock->push(true);
    }

    /**
     * Gives the lock back, and forgets it when nobody is holding or waiting for it.
     *
     * Forgetting is what keeps the map from growing with every thread ever spoken to. It is only
     * safe while there is no waiter: dropping a channel somebody is parked on would leave that
     * coroutine waiting on a mutex no later acquirer can ever take.
     */
    public function release(string $key): void
    {
        $lock = $this->locks[$key] ?? null;
        if ($lock === null) {
            return;
        }

        $held = !$lock->isEmpty();
        if ($held) {
            $lock->pop();
        }

        $stats = $lock->stats();
        $parked = (int) ($stats['producer_num'] ?? 0) + (int) ($stats['consumer_num'] ?? 0);
        $taken = !$lock->isEmpty();
        if ($parked > 0 || $taken) {
            return;
        }

        unset($this->locks[$key]);
    }
}
