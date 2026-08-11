<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use function array_keys;
use function count;
use function microtime;

/**
 * Which process belongs to which thread, and the questions the reclaiming rules ask of it.
 *
 * The rules differ in what they do — one gives up the least recently used, another gives up
 * whatever nobody has needed for a while — but they ask the same two things of every process:
 * is it answering a turn, and when was it last used. Those questions live here, next to the map
 * they read, so that no rule has to reach into another rule's bookkeeping to answer them.
 *
 * Nothing here is persisted. A map that is lost rebuilds itself on the next turn.
 *
 * @api
 */
final class ProcessTable
{
    /** @var array<string, AgentProcess> the process of each thread, by thread id */
    private array $processes = [];

    /** @return AgentProcess|null the thread's process, whether or not it is still running */
    public function get(string $key): ?AgentProcess
    {
        return $this->processes[$key] ?? null;
    }

    /** Takes the thread's process into the table, replacing whatever was there. */
    public function put(string $key, AgentProcess $process): void
    {
        $this->processes[$key] = $process;
    }

    /**
     * Removes the thread's process and hands it over.
     *
     * Taking it out before anything is done to it is the point: ending a process yields while it
     * waits, and what is on its way out must not be handed to a turn that begins in the meantime.
     *
     * @return AgentProcess|null null when the thread had none
     */
    public function take(string $key): ?AgentProcess
    {
        $process = $this->processes[$key] ?? null;
        unset($this->processes[$key]);

        return $process;
    }

    /**
     * Removes the thread's process, but only while it is still going unused.
     *
     * Between being listed as unused and being let go of, a process can be handed a turn: letting
     * go of one yields, and beginning a turn does not, so a whole turn can start inside that gap.
     * Whoever reclaims therefore asks again here, at the moment it takes — a list drawn up a
     * moment ago is a statement about the past, and reclaiming a process mid-turn kills the turn.
     *
     * @param float $idleSeconds how long a process may go unused
     *
     * @return AgentProcess|null null when the thread has no process, or when the one it has is
     *                           answering a turn or has been used since the list was drawn up
     */
    public function takeIfIdle(string $key, float $idleSeconds): ?AgentProcess
    {
        $process = $this->processes[$key] ?? null;
        if ($process === null) {
            return null;
        }

        $idle = self::idle($process, $idleSeconds, microtime(true));
        if (!$idle) {
            return null;
        }

        unset($this->processes[$key]);

        return $process;
    }

    /**
     * @return int how many processes are being held, which is what the limit is about
     *
     * @mutation-free
     */
    public function count(): int
    {
        return count($this->processes);
    }

    /**
     * @return string|null the thread whose process has gone unused the longest, or null when
     *                     every one of them is answering a turn and none may be taken away
     */
    public function leastRecentlyUsed(): ?string
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
     * @return list<string> every thread that has a process, in the order they were taken in
     *
     * @mutation-free
     */
    public function keys(): array
    {
        return array_keys($this->processes);
    }

    /**
     * @param float $idleSeconds how long a process may go unused
     *
     * @return float|null when the first process falls out of use, as an absolute {@see microtime}
     *                    value, or null while every one of them is answering a turn
     */
    public function nextDeadline(float $idleSeconds): ?float
    {
        $soonest = null;
        foreach ($this->processes as $process) {
            $deadline = $process->lastUsedAt + $idleSeconds;
            if ($process->busy || $soonest !== null && $deadline >= $soonest) {
                continue;
            }

            $soonest = $deadline;
        }

        return $soonest;
    }

    /**
     * @param float $now the moment being asked about, passed in so that a whole list is judged
     *                   against one reading of the clock
     *
     * @return bool whether the process is answering nothing and has not been used for a while
     *
     * @mutation-free
     */
    private static function idle(AgentProcess $process, float $idleSeconds, float $now): bool
    {
        return !$process->busy && $now >= ($process->lastUsedAt + $idleSeconds);
    }
}
