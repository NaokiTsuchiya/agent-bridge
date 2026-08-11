<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Runner;

use NaokiTsuchiya\AgentBridge\Tests\Support\Json;

use function array_filter;
use function array_slice;
use function array_unique;
use function array_values;
use function explode;
use function file_get_contents;
use function is_array;
use function is_file;
use function is_string;
use function trim;

/**
 * What the fake CLI wrote down about being run, read back from the outside.
 *
 * The runner drives its children as opaque processes, so these two files are the only window on
 * questions a test has to answer: how many processes were started and with which arguments, and
 * whether two turns were served by one process or by two.
 */
final readonly class FakeCliRecords
{
    /** @param string $home the `FAKE_CLAUDE_HOME` the processes under test were given */
    public function __construct(
        private string $home,
    ) {}

    /**
     * @return list<array<array-key, mixed>> one entry per process started, oldest first
     */
    public function starts(): array
    {
        return $this->entries('invocations.jsonl', 'start');
    }

    /**
     * The arguments of one start, without the program name.
     *
     * @return list<string>
     */
    public function argumentsOf(int $index): array
    {
        $start = $this->starts()[$index] ?? [];
        $argv = array_values(array_filter(Json::node($start, 'argv'), is_string(...)));

        // The program name is at index 0, and no assertion is about it.
        return array_slice($argv, offset: 1);
    }

    /** @return list<int> the pids that were handed a line of input, without repeats */
    public function inputPids(): array
    {
        return $this->pids($this->entries('invocations.jsonl', 'stdin'));
    }

    /** @return list<int> the pids that ran a turn, without repeats */
    public function turnPids(): array
    {
        return $this->pids($this->entries('turns.jsonl', null));
    }

    /** @return list<array<array-key, mixed>> every turn record, in order */
    public function turns(): array
    {
        return $this->entries('turns.jsonl', null);
    }

    /**
     * The turn records paired into intervals, in the order the turns began.
     *
     * A turn that was never answered — killed by a timeout, or still running when this was read —
     * keeps its beginning and has no end, which is exactly what a test about a timeout looks for.
     *
     * @return list<TurnSpan>
     */
    public function spans(): array
    {
        /** @var array<string, TurnSpan> $spans */
        $spans = [];
        foreach ($this->turns() as $entry) {
            $span = TurnSpan::fromRecord($entry);
            $begun = $spans[$span->key()] ?? null;
            $spans[$span->key()] = $begun?->answeredAt($span->startedAt) ?? $span;
        }

        return array_values($spans);
    }

    /**
     * @param string|null $event the `event` value to keep, or null to keep every line
     *
     * @return list<array<array-key, mixed>>
     */
    private function entries(string $file, ?string $event): array
    {
        $path = "{$this->home}/{$file}";
        $exists = is_file($path);
        $raw = $exists ? file_get_contents($path) : false;
        if (!is_string($raw)) {
            return [];
        }

        $entries = [];
        foreach (explode("\n", trim($raw)) as $line) {
            $decoded = Json::decode($line);
            if (!is_array($decoded) || $event !== null && Json::text($decoded, 'event') !== $event) {
                continue;
            }

            $entries[] = $decoded;
        }

        return $entries;
    }

    /**
     * @param list<array<array-key, mixed>> $entries
     *
     * @return list<int>
     */
    private function pids(array $entries): array
    {
        $pids = [];
        foreach ($entries as $entry) {
            $pid = Json::integer($entry, 'pid');
            if ($pid === null) {
                continue;
            }

            $pids[] = $pid;
        }

        return array_values(array_unique($pids));
    }
}
