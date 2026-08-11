<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Support;

use function dirname;
use function getenv;
use function is_string;

/**
 * Which `claude`-shaped binary a test starts, chosen by the environment rather than by the code.
 *
 * `AGENT_BRIDGE_CLAUDE_BIN` names the binary. Unset means the real `claude` on PATH. Pointing it
 * at {@see self::fake()} runs the same suite without login, network or billing — which is how a
 * CI job, or anyone without a logged-in Claude Code, gets to run tests that would otherwise be
 * out of reach.
 *
 * One thing is deliberately not configurable: the unit group always uses the fake ({@see fake()}
 * is what its contract subclass returns, not this). If an environment variable could push the
 * real binary into the unit group, `composer test:unit` would stop being runnable on a machine
 * with no Claude Code, which is the property CI depends on.
 */
final class ClaudeBinary
{
    /** @return string the fake in this repository, by a path any caller can hand to a shell */
    public static function fake(): string
    {
        return dirname(__DIR__, levels: 2) . '/tests/Fake/bin/claude';
    }

    /** @return string what `AGENT_BRIDGE_CLAUDE_BIN` names, or the real `claude` on PATH */
    public static function fromEnvironment(): string
    {
        $binary = getenv('AGENT_BRIDGE_CLAUDE_BIN');

        return is_string($binary) && $binary !== '' ? $binary : 'claude';
    }
}
