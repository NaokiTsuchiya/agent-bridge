<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use Closure;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Swoole\Coroutine\Channel;

/**
 * The children the runner is holding on to, and the rules for letting them go.
 *
 * A process here is a cache, never the truth: Claude Code keeps the transcript, so reclaiming one
 * costs a restart and nothing else. That is what makes the rules safe — the one used least
 * recently is given up when there are too many ({@see makeRoom()}), one nobody has needed for a
 * while is given up on its own ({@see IdleWatch}), and neither ever touches a process that is in
 * the middle of a turn.
 *
 * Only usable from inside a coroutine: the waiting is done with channels.
 *
 * @api
 */
final class ProcessPool
{
    /** Which process belongs to which thread, and when each was last used. */
    private ProcessTable $table;

    /** How a process is ended once it has been taken out of the table. */
    private ProcessRelease $release;

    /** Gives up processes that have gone unused, on a coroutine of its own. */
    private IdleWatch $watch;

    /** Wakes whoever is waiting for a turn to end so that a new process can start. */
    private Channel $roomWake;

    /**
     * @param LifecycleSettings $limits            how long a process may sit idle and how many
     *                                             there may be
     * @param float             $closeGraceSeconds how long a process is given to end on its own
     *                                             after its input is closed, before it is killed
     */
    public function __construct(
        private LifecycleSettings $limits,
        #[CloseGraceSeconds]
        float $closeGraceSeconds,
    ) {
        $this->table = new ProcessTable();
        $this->release = new ProcessRelease($closeGraceSeconds);
        $this->roomWake = new Channel(1);
        $this->watch = new IdleWatch($this->table, $this->release, $limits->idleSeconds, function (): void {
            $this->freed();
        });
    }

    /** @return AgentProcess|null the thread's process when it is still running, null otherwise */
    public function live(ThreadId $thread): ?AgentProcess
    {
        $process = $this->table->get($thread->value);
        if ($process === null) {
            return null;
        }

        $running = $process->isRunning();
        if ($running) {
            return $process;
        }

        // A dead process is let go of here rather than left in the table: it would otherwise count
        // against the limit and keep the watch awake over something that cannot answer.
        $this->discard($thread);

        return null;
    }

    /**
     * Makes room, starts a process through the caller's closure, and takes it into the pool.
     *
     * @param Closure(): (AgentProcess|null) $launch what actually starts one; the pool decides
     *                                               when there is room for it, not how it is run
     *
     * @return AgentProcess|null null when the closure could not start one
     */
    public function admit(ThreadId $thread, Closure $launch): ?AgentProcess
    {
        $this->makeRoom();

        $process = $launch();
        if ($process === null) {
            return null;
        }

        $this->table->put($thread->value, $process);
        $this->watch->start();

        return $process;
    }

    /**
     * Marks the thread's process as answering a turn, which puts it out of reach of reclaiming.
     *
     * The last-used stamp is deliberately left alone until the turn ends: while a process is busy
     * it cannot be reclaimed anyway, and moving the stamp now would make a long turn look recently
     * used the moment it finishes — the opposite of what "least recently used" is for.
     */
    public function beginTurn(ThreadId $thread): void
    {
        $process = $this->table->get($thread->value);
        if ($process === null) {
            return;
        }
        $process->beginTurn();
    }

    /** Marks the turn as over, which starts the idle clock and lets a waiting start proceed. */
    public function endTurn(ThreadId $thread): void
    {
        $process = $this->table->get($thread->value);
        $process?->endTurn();

        $this->freed();
    }

    /** Ends the thread's process the polite way: end of input first, killing only if it lingers. */
    public function drop(ThreadId $thread): void
    {
        $process = $this->table->take($thread->value);
        if ($process === null) {
            return;
        }

        $this->release->stop($process);
        $this->freed();
    }

    /** Ends the thread's process at once, for when waiting on it is exactly what went wrong. */
    public function discard(ThreadId $thread): void
    {
        $process = $this->table->take($thread->value);
        if ($process === null) {
            return;
        }

        $this->release->kill($process);
        $this->freed();
    }

    /** @return int how many processes are being held, which is what the limit is about */
    public function count(): int
    {
        return $this->table->count();
    }

    /** Waits, reclaiming as it goes, until the pool has room for one more process. */
    private function makeRoom(): void
    {
        while ($this->table->count() >= $this->limits->maxProcesses) {
            $victim = $this->table->leastRecentlyUsed();
            $process = $victim === null ? null : $this->table->take($victim);
            if ($process === null) {
                // Everything in the pool is answering a turn, and none of those may be taken
                // away. A turn cannot outlast the turn timeout, so this wait ends in a retry.
                $this->roomWake->pop($this->limits->turnSeconds);

                continue;
            }

            $this->release->stop($process);
            $this->freed();
        }
    }

    /** Tells both waiters that the pool has room it did not have, without ever parking on one. */
    private function freed(): void
    {
        $full = $this->roomWake->isFull();
        if (!$full) {
            $this->roomWake->push(true);
        }

        $this->watch->nudge();
    }
}
