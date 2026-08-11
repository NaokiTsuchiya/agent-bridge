<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Support;

use function basename;
use function explode;
use function getmypid;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function shell_exec;
use function sscanf;

/**
 * The children this PHP process still has, as the operating system sees them.
 *
 * Read from `ps` rather than from anything the code under test keeps, because the failure being
 * looked for is precisely the one the code does not know about: a child that was killed but never
 * collected stays a process — state `Z`, defunct — and only the process table shows it.
 *
 * The columns come back with no more shape guarantee than a decoded JSON line has, so they are
 * read through {@see Json} like everything else of that kind here.
 */
final class ChildProcesses
{
    /**
     * What running the question itself looks like in the answer.
     *
     * @var list<string>
     */
    private const array READERS = ['sh', 'ps'];

    /** @return list<string> one `<pid> <state>` per surviving child, empty when there are none */
    public static function all(): array
    {
        $mine = getmypid();
        // Four columns with their headers suppressed, spelled the same way on macOS and on the
        // procps `ps` of the CI image.
        $table = shell_exec('ps -A -o pid=,ppid=,stat=,comm=');
        if (!is_int($mine) || !is_string($table)) {
            return [];
        }

        $children = [];
        foreach (explode("\n", $table) as $row) {
            /** @var list<mixed>|int|null $columns */
            $columns = sscanf($row, '%d %d %s %s');
            if (!is_array($columns)) {
                continue;
            }

            $pid = Json::integer($columns, 0);
            $state = Json::text($columns, 2);
            $command = Json::text($columns, 3) ?? '';
            $ours = $pid !== null && $state !== null && Json::integer($columns, 1) === $mine;
            // Reading the process table is itself two children of this process — the shell and the
            // `ps` inside it — and they are alive exactly while they are being read.
            $asking = in_array(basename($command), self::READERS, strict: true);
            if (!$ours || $asking) {
                continue;
            }

            $children[] = "{$pid} {$state}";
        }

        return $children;
    }
}
