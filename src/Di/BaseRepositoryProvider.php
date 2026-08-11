<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Di;

use Override;
use Ray\Di\ProviderInterface;

use function getcwd;
use function getenv;

/**
 * Where the worktrees are cut from, read at the moment it is asked for.
 *
 * A provider rather than a bound value on purpose: Ray.Compiler freezes whatever `toInstance()` is
 * given into a script that ships with the image, so a deployment path bound that way would be the
 * build machine's, not the running one's. `BakedPathGuard` fails a compile that does it.
 *
 * @implements ProviderInterface<string>
 *
 * @api
 */
final class BaseRepositoryProvider implements ProviderInterface
{
    /** The environment variable naming the repository, unset in development. */
    public const string VARIABLE = 'AGENT_BRIDGE_REPOSITORY';

    /** Falls back to the working directory, which is the repository when the server is started in it. */
    #[Override]
    public function get(): string
    {
        $repository = getenv(self::VARIABLE);

        if ($repository === false || $repository === '') {
            return (string) getcwd();
        }

        return $repository;
    }
}
