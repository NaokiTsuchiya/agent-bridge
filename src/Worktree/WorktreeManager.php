<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Worktree;

use NaokiTsuchiya\AgentBridge\Thread\ThreadDerivation;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;

use function array_map;
use function basename;
use function dirname;
use function escapeshellarg;
use function exec;
use function implode;
use function is_dir;
use function realpath;
use function str_starts_with;
use function strlen;
use function substr;

final class WorktreeManager
{
    /** What `git symbolic-ref refs/remotes/origin/HEAD` answers with. */
    private const string ORIGIN_HEAD_PREFIX = 'refs/remotes/origin/';

    /** What `git symbolic-ref HEAD` answers with while a branch is checked out. */
    private const string LOCAL_BRANCH_PREFIX = 'refs/heads/';

    /** The repository the worktrees are cut from; which one it is stays the caller's decision. */
    public function __construct(
        private string $baseRepository,
    ) {}

    /**
     * The absolute path of the thread's worktree, creating or recovering it when the directory is gone.
     *
     * @throws WorktreeException
     */
    public function worktreeFor(ThreadId $thread): string
    {
        $base = self::resolve($this->baseRepository);
        $relative = ThreadDerivation::worktreePath($thread);
        $path = self::resolve("{$base}/{$relative}");

        if (!str_starts_with($path, "{$base}/")) {
            throw new WorktreeException(
                "The worktree of \"{$thread->value}\" resolves to \"{$path}\", which is outside \"{$base}\".",
            );
        }

        $exists = is_dir($path);

        if ($exists) {
            return $path;
        }

        // A directory that is gone may still be registered as a worktree, and git
        // refuses to add over a stale registration (it names prune in the refusal).
        $this->git(['worktree', 'prune']);

        $branch = ThreadDerivation::branchName($thread);
        [$branchExitCode] = $this->git(['show-ref', '--verify', '--quiet', self::LOCAL_BRANCH_PREFIX . $branch]);

        $arguments = $branchExitCode === 0
            ? ['worktree', 'add', $path, $branch]
            : ['worktree', 'add', '-b', $branch, $path, $this->defaultBranch()];

        [$exitCode, $output] = $this->git($arguments);

        if ($exitCode !== 0) {
            $command = implode(' ', $arguments);

            throw new WorktreeException("git {$command} failed with exit code {$exitCode}: {$output}");
        }

        return $path;
    }

    /**
     * The branch every worktree is cut from, resolved without hardcoding a name.
     *
     * @throws WorktreeException
     */
    private function defaultBranch(): string
    {
        [$originExitCode, $originRef] = $this->git(['symbolic-ref', self::ORIGIN_HEAD_PREFIX . 'HEAD']);
        $fromOrigin = $originExitCode === 0 ? self::branchIn($originRef, self::ORIGIN_HEAD_PREFIX) : null;

        if ($fromOrigin !== null) {
            return $fromOrigin;
        }

        [$headExitCode, $headRef] = $this->git(['symbolic-ref', 'HEAD']);
        $fromHead = $headExitCode === 0 ? self::branchIn($headRef, self::LOCAL_BRANCH_PREFIX) : null;

        if ($fromHead !== null) {
            return $fromHead;
        }

        throw new WorktreeException(
            "Cannot resolve the default branch of \"{$this->baseRepository}\": neither "
            . self::ORIGIN_HEAD_PREFIX
            . 'HEAD nor HEAD names a branch.',
        );
    }

    /** The branch name a fully qualified ref carries, keeping any slash the name itself contains. */
    private static function branchIn(string $ref, string $prefix): ?string
    {
        if (!str_starts_with($ref, $prefix)) {
            return null;
        }

        $name = substr($ref, strlen($prefix));

        return $name === '' ? null : $name;
    }

    /**
     * Runs git inside the base repository.
     *
     * @param list<string> $arguments
     *
     * @return array{int, string} exit code and the combined output, stderr included
     */
    private function git(array $arguments): array
    {
        $directory = escapeshellarg($this->baseRepository);
        $quoted = implode(' ', array_map(escapeshellarg(...), $arguments));

        $output = [];
        $exitCode = 0;
        exec("git -C {$directory} {$quoted} 2>&1", $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }

    /**
     * An absolute path with every symlink on it resolved, for paths whose leaf does not exist yet.
     *
     * `realpath` gives up on a missing leaf, so the deepest existing ancestor is resolved and the
     * rest is appended. Without this a symlinked `.worktrees` would hide a path outside the base
     * repository behind a string that looks like it is inside.
     */
    private static function resolve(string $path): string
    {
        $resolved = realpath($path);

        if ($resolved !== false) {
            return $resolved;
        }

        $parent = dirname($path);

        if ($parent === $path) {
            return $path;
        }

        $resolvedParent = self::resolve($parent);
        $name = basename($path);

        return "{$resolvedParent}/{$name}";
    }
}
