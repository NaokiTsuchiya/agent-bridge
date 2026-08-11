<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use Closure;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

use function count;
use function microtime;

/**
 * The children the runner is holding on to, and the rules for letting them go.
 *
 * A process here is a cache, never the truth: Claude Code keeps the transcript, so reclaiming one
 * costs a restart and nothing else. That is what makes all three rules safe — an idle process is
 * reclaimed once nobody has used it for a while, the least recently used one is reclaimed when
 * there are too many, and neither ever touches a process that is in the middle of a turn.
 *
 * Nothing here is persisted. A map that is lost is a map that rebuilds itself on the next turn.
 *
 * Only usable from inside a coroutine: the waiting is done with channels.
 *
 * The three rules and the watch that applies the idle one are here together, and the linter is
 * told so below: each of them decides by reading the same two things off every process — whether
 * it is answering a turn, and when it was last used — and a class that held only some of them
 * would have to expose that state to whoever held the rest.
 *
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 *
 * @api
 */
final class ProcessPool
{
    /** How long a terminated child is given to disappear before it is left to the system. */
    private const float TERMINATION_GRACE = 2.0;

    /** @var array<string, AgentProcess> the live process of each thread, by thread id */
    private array $processes = [];

    /** Wakes the idle watch when something changed under it. */
    private Channel $idleWake;

    /** Wakes whoever is waiting for a turn to end so that a new process can start. */
    private Channel $roomWake;

    /** The idle watch's coroutine id, or 0 when no watch is running. */
    private int $watch = 0;

    /**
     * @param LifecycleSettings $limits            how long a process may sit idle and how many
     *                                             there may be
     * @param float             $closeGraceSeconds how long a process is given to end on its own
     *                                             after its input is closed, before it is killed
     */
    public function __construct(
        private LifecycleSettings $limits,
        private float $closeGraceSeconds,
    ) {
        $this->idleWake = new Channel(1);
        $this->roomWake = new Channel(1);
    }

    /** @return AgentProcess|null the thread's process when it is still running, null otherwise */
    public function live(ThreadId $thread): ?AgentProcess
    {
        $process = $this->processes[$thread->value] ?? null;
        if ($process === null) {
            return null;
        }

        $running = $process->isRunning();
        if ($running) {
            return $process;
        }

        // A dead process is let go here rather than left in the map: it would otherwise count
        // against the limit and keep the idle watch awake over something that cannot answer.
        $this->kill($thread->value);

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

        $this->processes[$thread->value] = $process;
        $this->watchIdle();

        return $process;
    }

    /**
     * Marks the thread's process as answering a turn, which puts it out of reach of reclaiming.
     *
     * The last-used stamp is deliberately left alone until the turn ends: while a process is
     * busy it cannot be reclaimed anyway, and moving the stamp now would make a long turn look
     * recently used the moment it finishes — which is the opposite of what "least recently used"
     * is for.
     */
    public function beginTurn(ThreadId $thread): void
    {
        $process = $this->processes[$thread->value] ?? null;
        if ($process === null) {
            return;
        }

        $process->busy = true;
    }

    /** Marks the turn as over, which starts the idle clock and lets a waiting start proceed. */
    public function endTurn(ThreadId $thread): void
    {
        $process = $this->processes[$thread->value] ?? null;
        if ($process !== null) {
            $process->busy = false;
            $process->lastUsedAt = microtime(true);
        }

        $this->wake();
    }

    /** Ends the thread's process the polite way: end of input first, killing only if it lingers. */
    public function drop(ThreadId $thread): void
    {
        $this->stop($thread->value);
    }

    /** Ends the thread's process at once, for when waiting on it is exactly what went wrong. */
    public function discard(ThreadId $thread): void
    {
        $this->kill($thread->value);
    }

    /** @return int how many processes are being held, which is what the limit is about */
    public function count(): int
    {
        return count($this->processes);
    }

    /** Lets go of one process by closing its input and waiting for it to end on its own. */
    private function stop(string $key): void
    {
        $process = $this->take($key);
        if ($process === null) {
            return;
        }

        // End of input is what makes a `claude` finish and exit; without it the grace below would
        // be spent on a process that is only waiting for the next turn.
        $process->closeInput();
        $ended = $process->awaitExit($this->closeGraceSeconds);
        if (!$ended) {
            $this->reap($process);

            return;
        }

        $this->collect($process);
    }

    /** Lets go of one process the short way, for when waiting on it is what went wrong. */
    private function kill(string $key): void
    {
        $process = $this->take($key);
        if ($process === null) {
            return;
        }

        $this->reap($process);
    }

    /**
     * Takes a process out of the map, before any waiting starts.
     *
     * Both ways of letting go of one yield while they wait, and what is on its way out must not
     * be handed to a turn that begins in the meantime.
     *
     * @return AgentProcess|null null when the thread had none
     */
    private function take(string $key): ?AgentProcess
    {
        $process = $this->processes[$key] ?? null;
        unset($this->processes[$key]);

        return $process;
    }

    /** Kills a child that will not end by itself, and collects it. */
    private function reap(AgentProcess $process): void
    {
        $process->terminate();
        $process->awaitExit(self::TERMINATION_GRACE);
        $this->collect($process);
    }

    /** Closes the pipes and collects the child, which is the only reaping there is. */
    private function collect(AgentProcess $process): void
    {
        // No SIGCHLD handler is installed anywhere, precisely so that nothing races with this:
        // a handler that reaped the same child would leave one of the two collecting a stranger.
        $process->release();
        $this->wake();
    }

    /** Waits, reclaiming as it goes, until the pool has room for one more process. */
    private function makeRoom(): void
    {
        while (count($this->processes) >= $this->limits->maxProcesses) {
            $victim = $this->leastRecentlyUsed();
            if ($victim !== null) {
                $this->stop($victim);

                continue;
            }

            // Everything in the pool is answering a turn, and none of those may be taken away.
            // A turn cannot outlast the turn timeout, so this wait always ends with a retry.
            $this->roomWake->pop($this->limits->turnSeconds);
        }
    }

    /** @return string|null the thread id of the process to give up first, null when all are busy */
    private function leastRecentlyUsed(): ?string
    {
        $oldest = null;
        $found = null;
        foreach ($this->processes as $key => $process) {
            if ($process->busy || $oldest !== null && $process->lastUsedAt >= $oldest) {
                continue;
            }

            $oldest = $process->lastUsedAt;
            $found = $key;
        }

        return $found;
    }

    /**
     * Starts the coroutine that reclaims idle processes, unless one is already running.
     *
     * It is not a timer and not a loop with a fixed tick: it sleeps exactly until the nearest
     * idle deadline, is woken early by anything that moves a deadline, and **ends as soon as the
     * pool is empty**. That ending is what lets `Swoole\Coroutine\run()` return.
     */
    private function watchIdle(): void
    {
        $running = $this->watch !== 0 && Coroutine::exists($this->watch);
        if ($running) {
            return;
        }

        $watch = Coroutine::create(function (): void {
            while ($this->processes !== []) {
                $wait = $this->untilNextDeadline();
                if ($wait > 0.0) {
                    $this->idleWake->pop($wait);
                }

                $this->reapIdle();
            }

            $this->watch = 0;
        });

        $this->watch = $watch === false ? 0 : $watch;
    }

    /** Lets go of every process that has been sitting on no turn for longer than it may. */
    private function reapIdle(): void
    {
        $now = microtime(true);
        foreach ($this->processes as $key => $process) {
            $expired = !$process->busy && $now >= ($process->lastUsedAt + $this->limits->idleSeconds);
            if (!$expired) {
                continue;
            }

            $this->stop($key);
        }
    }

    /** @return float seconds until the nearest idle deadline; a full idle span when all are busy */
    private function untilNextDeadline(): float
    {
        $soonest = null;
        foreach ($this->processes as $process) {
            $deadline = $process->lastUsedAt + $this->limits->idleSeconds;
            if ($process->busy || $soonest !== null && $deadline >= $soonest) {
                continue;
            }

            $soonest = $deadline;
        }

        if ($soonest === null) {
            return $this->limits->idleSeconds;
        }

        $left = $soonest - microtime(true);

        return $left > 0.0 ? $left : 0.0;
    }

    /** Tells both waiters that the pool changed, without ever parking on a full channel. */
    private function wake(): void
    {
        foreach ([$this->idleWake, $this->roomWake] as $channel) {
            $full = $channel->isFull();
            if ($full) {
                continue;
            }

            $channel->push(true);
        }
    }
}
