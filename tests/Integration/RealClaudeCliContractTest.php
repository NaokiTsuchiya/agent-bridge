<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Integration;

use NaokiTsuchiya\AgentBridge\Tests\Contract\ClaudeCliContractTestCase;
use Override;
use PHPUnit\Framework\Attributes\Group;

/**
 * The contract, run against the real `claude` — the side that says whether the fake is still true.
 *
 * It needs a logged-in Claude Code and spends real turns, so it is out of the unit group and out
 * of CI. When it fails, the fake has drifted from the CLI and the fake is what gets fixed; the
 * shared body in {@see ClaudeCliContractTestCase} is what both sides answer to.
 */
#[Group('integration')]
final class RealClaudeCliContractTest extends ClaudeCliContractTestCase
{
    /** @return list<string> */
    #[Override]
    protected function binary(): array
    {
        return ['claude'];
    }

    /** @return array<string, string> */
    #[Override]
    protected function environment(): array
    {
        return [];
    }
}
