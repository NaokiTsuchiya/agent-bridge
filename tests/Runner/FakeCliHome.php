<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Runner;

use function putenv;

/** Points the fake CLI at a case's own state directory, and lets it go afterwards. */
final class FakeCliHome
{
    /** Points the fake at this case's home, so its sessions and recordings are its own. */
    public static function activate(string $home): void
    {
        putenv("FAKE_CLAUDE_HOME={$home}");
    }

    /** The environment is process-wide, so it is put back the way it was found. */
    public static function deactivate(): void
    {
        putenv('FAKE_CLAUDE_HOME');
        putenv('FAKE_CLAUDE_SCENARIO');
    }
}
