<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Runner;

use NaokiTsuchiya\AgentBridge\Tests\Support\Json;

use const INF;

/**
 * One turn of the fake CLI, from the moment it began to the moment it was answered.
 *
 * Concurrency is judged from these and never from the wall clock the test itself reads: whether
 * two turns ran at the same time is a question about the two intervals, and a test that instead
 * timed its own calls would be measuring the scheduler and the machine's load.
 */
final readonly class TurnSpan
{
    /**
     * @param int        $pid       which process ran the turn
     * @param string     $sessionId which session it belonged to
     * @param int        $turn      the 1-based turn number inside that process
     * @param float      $startedAt when the fake began the turn
     * @param float|null $endedAt   when it answered, or null when it never did
     */
    public function __construct(
        public int $pid,
        public string $sessionId,
        public int $turn,
        public float $startedAt,
        public ?float $endedAt,
    ) {}

    /** @param array<array-key, mixed> $record one line of the fake's `turns.jsonl` */
    public static function fromRecord(array $record): self
    {
        return new self(
            Json::integer($record, 'pid') ?? 0,
            Json::text($record, 'session_id') ?? '',
            Json::integer($record, 'turn') ?? 0,
            Json::number($record, 'at') ?? 0.0,
            endedAt: null,
        );
    }

    /** @return string what makes two records the same turn: the process, session and number */
    public function key(): string
    {
        return "{$this->pid}/{$this->sessionId}/{$this->turn}";
    }

    /** @return self the same turn, now with the moment it was answered */
    public function answeredAt(float $at): self
    {
        return new self($this->pid, $this->sessionId, $this->turn, $this->startedAt, $at);
    }

    /** @return bool whether the two turns were both under way at some moment */
    public function overlaps(self $other): bool
    {
        return $this->startedAt < ($other->endedAt ?? INF) && $other->startedAt < ($this->endedAt ?? INF);
    }

    /** @return bool whether this turn began only after the other one had been answered */
    public function startedAfter(self $other): bool
    {
        return $other->endedAt !== null && $this->startedAt >= $other->endedAt;
    }
}
