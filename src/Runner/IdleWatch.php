<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use Closure;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

use function microtime;

/**
 * Gives up processes that nobody has needed for a while, on its own coroutine.
 *
 * Not a timer and not a loop with a fixed tick: it sleeps exactly until the nearest moment a
 * process falls out of use, is woken early by anything that moves that moment, and **ends as
 * soon as the table is empty**. The ending matters — `Swoole\Coroutine\run()` waits for every
 * coroutine, so a watch that ran forever would keep an application from ever finishing.
 *
 * A process answering a turn is never idle, however long the turn takes; {@see ProcessTable}
 * answers both questions with that already accounted for.
 *
 * @api
 */
final class IdleWatch
{
    /** Woken by anything that changes when the next process falls out of use. */
    private Channel $wake;

    /** The sweeping coroutine's id, or 0 when none is running. */
    private int $coroutine = 0;

    /**
     * @param ProcessTable   $table       what is being watched
     * @param ProcessRelease $release     how a process that has gone unused is let go of
     * @param float          $idleSeconds how long a process may go unused
     * @param Closure(): void $reclaimed  told after each process is let go of, so that whoever is
     *                                    waiting for room can have another look
     */
    public function __construct(
        private ProcessTable $table,
        private ProcessRelease $release,
        private float $idleSeconds,
        private Closure $reclaimed,
    ) {
        $this->wake = new Channel(1);
    }

    /** Starts the watch unless one is already running; safe to call on every new process. */
    public function start(): void
    {
        $running = $this->coroutine !== 0 && Coroutine::exists($this->coroutine);
        if ($running) {
            return;
        }

        $coroutine = Coroutine::create(function (): void {
            $this->sweep();
        });

        $this->coroutine = $coroutine === false ? 0 : $coroutine;
    }

    /** Says that the table changed, without ever parking on a full channel. */
    public function nudge(): void
    {
        $full = $this->wake->isFull();
        if ($full) {
            return;
        }

        $this->wake->push(true);
    }

    /** Sleeps until the nearest deadline, reclaims, and repeats until nothing is left to watch. */
    private function sweep(): void
    {
        while ($this->table->count() > 0) {
            $left = $this->untilNextDeadline();
            if ($left > 0.0) {
                $this->wake->pop($left);
            }

            $this->reclaim();
        }

        $this->coroutine = 0;
    }

    /**
     * Lets go of every process that has been sitting on no turn for longer than it may.
     *
     * Every thread is walked and each one judged at the moment it is taken, rather than a list of
     * the unused ones being drawn up first: letting go of a process yields, beginning a turn does
     * not, so a whole turn can start on a later thread while this is busy with an earlier one. A
     * list would be a statement about the past, and acting on it would kill that turn.
     */
    private function reclaim(): void
    {
        foreach ($this->table->keys() as $key) {
            $process = $this->table->takeIfIdle($key, $this->idleSeconds);
            if ($process === null) {
                continue;
            }

            $this->release->stop($process);
            ($this->reclaimed)();
        }
    }

    /** @return float seconds until the nearest deadline; a whole idle span while all are busy */
    private function untilNextDeadline(): float
    {
        $deadline = $this->table->nextDeadline($this->idleSeconds);
        if ($deadline === null) {
            // Everything is answering a turn. Whichever finishes first nudges this awake, so the
            // wait only has to be something other than forever.
            return $this->idleSeconds;
        }

        $left = $deadline - microtime(true);

        return $left > 0.0 ? $left : 0.0;
    }
}
