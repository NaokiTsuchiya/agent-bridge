<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Support;

use PHPUnit\Framework\Assert;

use function array_map;
use function escapeshellarg;
use function exec;
use function file_put_contents;
use function implode;
use function realpath;

/**
 * A throwaway git repository for tests that need worktrees to be real.
 *
 * The identity and the initial branch are pinned so that nothing has to be configured on the
 * machine running this, and `commit.gpgsign` is turned off so that a developer's global setting
 * does not reach into these commits.
 */
final class GitRepository
{
    /** @return string the absolute, symlink-resolved path of a repository with one commit in it */
    public static function make(string $prefix): string
    {
        $path = realpath(TempDir::make($prefix));
        // The manager answers with resolved paths, and macOS hands out /private/var where the
        // test saw /var; an unresolved path here would never compare equal.
        Assert::assertIsString($path);

        self::git($path, ['init', '-b', 'main', '.']);
        self::git($path, ['config', 'user.name', 'agent-bridge']);
        self::git($path, ['config', 'user.email', 'agent-bridge@example.invalid']);
        self::git($path, ['config', 'commit.gpgsign', 'false']);

        $written = file_put_contents("{$path}/.gitignore", data: ".worktrees/\n");
        Assert::assertIsInt($written);

        self::git($path, ['add', '-A']);
        self::git($path, ['commit', '-m', 'init']);

        return $path;
    }

    /**
     * Deletes the repository and every worktree cut from it.
     *
     * `rm -rf` rather than an iterator: a worktree holds a `.git` file pointing back at the
     * repository, and the tree is only worth taking apart in the order the shell already does.
     */
    public static function remove(string $path): void
    {
        $output = [];
        $exitCode = 0;
        exec('rm -rf ' . escapeshellarg($path), $output, $exitCode);

        Assert::assertSame(0, $exitCode);
    }

    /** @param list<string> $arguments */
    private static function git(string $repository, array $arguments): void
    {
        $quoted = implode(' ', array_map(escapeshellarg(...), $arguments));
        $directory = escapeshellarg($repository);

        $output = [];
        $exitCode = 0;
        exec("git -C {$directory} {$quoted} 2>&1", $output, $exitCode);

        $command = implode(' ', $arguments);
        Assert::assertSame(0, $exitCode, "git {$command} failed: " . implode("\n", $output));
    }
}
