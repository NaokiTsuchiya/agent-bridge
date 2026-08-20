<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;
use Swoole\Runtime;

use function sys_get_temp_dir;

use const SWOOLE_HOOK_PROC;

/**
 * A working directory resolver that disables Swoole's proc hook immediately before a launch.
 *
 * Inside a coroutine with `SWOOLE_HOOK_PROC` enabled, `proc_open` never fails even for missing
 * binaries or nonexistent directories. Resolving the working directory is the final external call
 * before `proc_open` is called in both runners, allowing tests to selectively drop the proc hook
 * for specific launch attempts.
 */
final class HookOffBeforeLaunch implements WorkingDirectoryResolver
{
    /** How many times resolve() has been called. */
    private int $asked = 0;

    /**
     * @param string $path     the directory to return on successful launches
     * @param int    $failFrom the 1-based call count from which to drop the hook and return a missing directory
     */
    public function __construct(
        private string $path,
        private int $failFrom,
    ) {}

    /** @return string the resolved path, or a nonexistent directory when configured to fail */
    #[Override]
    public function resolve(ThreadId $thread): string
    {
        $this->asked++;
        if ($this->asked >= $this->failFrom) {
            Runtime::setHookFlags(Runtime::getHookFlags() & ~SWOOLE_HOOK_PROC);

            return sys_get_temp_dir() . '/agent-bridge-nonexistent-cwd';
        }

        return $this->path;
    }
}
