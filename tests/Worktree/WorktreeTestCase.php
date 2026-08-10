<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Worktree;

use Override;
use PHPUnit\Framework\TestCase;

use function array_map;
use function escapeshellarg;
use function exec;
use function file_put_contents;
use function implode;
use function is_dir;
use function mkdir;
use function realpath;
use function sys_get_temp_dir;
use function uniqid;

/**
 * The throwaway git repositories the WorktreeManager cases are run against.
 *
 * @internal
 */
abstract class WorktreeTestCase extends TestCase
{
    /** Each case gets its own tree, resolved so that it can be compared with what the manager returns. */
    protected string $root = '';

    /** Cases share no state, so each one gets a tree of its own under the system temporary directory. */
    #[Override]
    protected function setUp(): void
    {
        $temp = realpath(sys_get_temp_dir());
        self::assertIsString($temp);

        $suffix = uniqid();
        $root = "{$temp}/agent-bridge-{$suffix}";
        self::assertTrue(mkdir($root, permissions: 0o777, recursive: true));

        $this->root = $root;
    }

    /** Removed with `rm -rf` rather than an iterator so that the symlinked cases are unlinked, not followed. */
    #[Override]
    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    /**
     * A throwaway repository with the initial branch and the identity pinned, so CI has nothing to supply.
     *
     * @return string the absolute path of the repository
     */
    protected function initRepository(string $name): string
    {
        $path = "{$this->root}/{$name}";
        self::assertTrue(mkdir($path));

        self::git($path, ['init', '-b', 'main', '.']);
        self::git($path, ['config', 'user.name', 'agent-bridge']);
        self::git($path, ['config', 'user.email', 'agent-bridge@example.invalid']);
        // A developer's global `commit.gpgsign` would otherwise reach into these commits.
        self::git($path, ['config', 'commit.gpgsign', 'false']);

        $written = file_put_contents("{$path}/.gitignore", data: ".worktrees/\n");
        self::assertIsInt($written);

        self::git($path, ['add', '-A']);
        self::git($path, ['commit', '-m', 'init']);

        return $path;
    }

    /** Adds one commit carrying a single named file, in whichever working tree is given. */
    protected function commit(string $workingTree, string $file): void
    {
        $written = file_put_contents("{$workingTree}/{$file}", data: "content\n");
        self::assertIsInt($written);

        self::git($workingTree, ['add', '-A']);
        self::git($workingTree, ['commit', '-m', $file]);
    }

    /** Deletes a directory without following the symlinks inside it. */
    protected static function removeTree(string $path): void
    {
        self::assertTrue(is_dir($path));

        $quoted = escapeshellarg($path);
        $output = [];
        $exitCode = 0;
        exec("rm -rf {$quoted}", $output, $exitCode);

        self::assertSame(0, $exitCode);
    }

    /**
     * Runs git and fails the test if it does not succeed.
     *
     * @param list<string> $arguments
     */
    protected static function git(string $cwd, array $arguments): string
    {
        [$exitCode, $output] = self::runGit($cwd, $arguments);
        $command = implode(' ', $arguments);
        self::assertSame(0, $exitCode, "git {$command} failed: {$output}");

        return $output;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string} exit code and the combined output, stderr included
     */
    protected static function runGit(string $cwd, array $arguments): array
    {
        $directory = escapeshellarg($cwd);
        $quoted = implode(' ', array_map(escapeshellarg(...), $arguments));

        $output = [];
        $exitCode = 0;
        exec("git -C {$directory} {$quoted} 2>&1", $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }
}
