<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Fake\Claude;

use function getmypid;
use function json_encode;
use function microtime;

/**
 * The fake's flight recorder.
 *
 * A test drives the fake as an opaque process, so nothing inside it can be observed directly.
 * These two files are the whole window: `invocations.jsonl` answers "was a second process started,
 * and with which arguments and cwd", and `turns.jsonl` answers "when did each turn begin and end"
 * — which is what a test of serialized turns has to compare across processes.
 *
 * Every line is appended and carries the pid, so that concurrent processes stay tellable apart.
 */
final readonly class Recorder
{
    /** @param FakeHome $home where the two recording files live */
    public function __construct(
        private FakeHome $home,
    ) {}

    /** @param list<string> $argv */
    public function invocation(array $argv, string $cwd): void
    {
        $this->append('invocations.jsonl', ['event' => 'start', 'argv' => $argv, 'cwd' => $cwd]);
    }

    /** @param string $line the stdin line exactly as it arrived, before any parsing */
    public function stdin(string $line): void
    {
        $this->append('invocations.jsonl', ['event' => 'stdin', 'line' => $line]);
    }

    /** @param string $phase `start` before the reply is produced, `end` once the result line is out */
    public function turn(string $sessionId, int $turn, string $phase): void
    {
        $this->append('turns.jsonl', ['session_id' => $sessionId, 'turn' => $turn, 'phase' => $phase]);
    }

    /** @param array<string, mixed> $entry */
    private function append(string $file, array $entry): void
    {
        $json = json_encode([...$entry, 'pid' => getmypid(), 'at' => microtime(true)]);
        if ($json === false) {
            return;
        }

        $this->home->appendLine($file, $json);
    }
}
