<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function exec;
use function implode;

#[Group('integration')]
final class ClaudeCliTest extends TestCase
{
    public function testClaudeCliReportsItsVersion(): void
    {
        $output = [];
        $exitCode = 1;
        exec('claude --version 2>/dev/null', $output, $exitCode);

        self::assertSame(0, $exitCode, 'A logged-in Claude Code CLI must be on PATH for the integration group.');
        self::assertMatchesRegularExpression('/\d+\.\d+\.\d+/', implode("\n", $output));
    }
}
