<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Thread;

use InvalidArgumentException;

use function explode;
use function str_contains;

final class ThreadId
{
    public string $platform;
    public string $nativeId;

    /** @throws InvalidArgumentException */
    public function __construct(
        public string $value,
    ) {
        if (!str_contains($value, ':')) {
            throw new InvalidArgumentException("ThreadId must be \"PLATFORM:NATIVE_ID\", got \"{$value}\".");
        }

        $parts = explode(':', $value, limit: 2);
        $platform = $parts[0];
        // The `?? ''` is unreachable after the check above; it is there because the
        // analyzer types `explode` as a non-empty list without knowing the limit.
        $nativeId = $parts[1] ?? '';

        if ('' === $platform) {
            throw new InvalidArgumentException("ThreadId must have a non-empty PLATFORM, got \"{$value}\".");
        }

        if ('' === $nativeId) {
            throw new InvalidArgumentException("ThreadId must have a non-empty NATIVE_ID, got \"{$value}\".");
        }

        // A ThreadId becomes a directory name and a branch name, so anything that
        // could take the worktree outside the base repository is rejected here.
        if (str_contains($platform, '/') || str_contains($nativeId, '/')) {
            throw new InvalidArgumentException("ThreadId must not contain \"/\", got \"{$value}\".");
        }

        if (str_contains($nativeId, '..')) {
            throw new InvalidArgumentException("ThreadId NATIVE_ID must not contain \"..\", got \"{$value}\".");
        }

        $this->platform = $platform;
        $this->nativeId = $nativeId;
    }
}
