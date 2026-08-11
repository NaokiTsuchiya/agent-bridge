<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function is_executable;
use function putenv;

/** The switch that decides whether a suite talks to the real CLI or to the fake. */
final class ClaudeBinaryTest extends TestCase
{
    /** Nothing configured means the real thing, so that an unset variable is never a silent fake. */
    #[Test]
    public function withoutTheVariableTheRealCliIsUsed(): void
    {
        putenv('AGENT_BRIDGE_CLAUDE_BIN');

        self::assertSame('claude', ClaudeBinary::fromEnvironment());
    }

    /** The variable is what lets a machine with no Claude Code run the tests that need one. */
    #[Test]
    public function theVariableSelectsTheBinary(): void
    {
        putenv('AGENT_BRIDGE_CLAUDE_BIN=' . ClaudeBinary::fake());

        $selected = ClaudeBinary::fromEnvironment();
        putenv('AGENT_BRIDGE_CLAUDE_BIN');

        self::assertSame(ClaudeBinary::fake(), $selected);
    }

    /** An empty value is a mistake in the setup, not a request for an empty command. */
    #[Test]
    public function anEmptyVariableFallsBackToTheRealCli(): void
    {
        putenv('AGENT_BRIDGE_CLAUDE_BIN=');

        $selected = ClaudeBinary::fromEnvironment();
        putenv('AGENT_BRIDGE_CLAUDE_BIN');

        self::assertSame('claude', $selected);
    }

    /** The fake is named by a path that can be started, not by a name looked up on PATH. */
    #[Test]
    public function theFakeIsNamedByAnExecutablePath(): void
    {
        self::assertTrue(is_executable(ClaudeBinary::fake()));
    }
}
