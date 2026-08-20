<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Integration;

use NaokiTsuchiya\AgentBridge\Support\ClaudeBinary;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function escapeshellarg;
use function exec;

#[Group('integration')]
final class ClaudeCliTest extends TestCase
{
    /** Guards the precondition of the integration group: a usable CLI, whichever one is selected. */
    #[Test]
    public function claudeCliReportsItsVersion(): void
    {
        $output = [];
        $exitCode = 1;
        $binary = escapeshellarg(ClaudeBinary::fromEnvironment());
        $lastLine = exec("{$binary} --version 2>/dev/null", $output, $exitCode);

        self::assertSame(0, $exitCode, "{$binary} must be a usable CLI for the integration group.");
        self::assertIsString($lastLine);
        self::assertMatchesRegularExpression('/\d+\.\d+\.\d+/', $lastLine);
    }
}
