<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Git;

use Override;

use function array_map;
use function escapeshellarg;
use function exec;
use function implode;

final class Git implements GitInterface
{
    /** stderr is folded into stdout so that a failure carries its reason back to the caller. */
    #[Override]
    public function run(string $repository, array $arguments): array
    {
        $directory = escapeshellarg($repository);
        $quoted = implode(' ', array_map(escapeshellarg(...), $arguments));

        $output = [];
        $exitCode = 0;
        exec("git -C {$directory} {$quoted} 2>&1", $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }
}
