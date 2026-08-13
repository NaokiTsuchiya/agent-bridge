<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Worktree;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Git\Git;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use NaokiTsuchiya\AgentBridge\Worktree\WorktreeException;
use NaokiTsuchiya\AgentBridge\Worktree\WorktreeManager;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function chdir;
use function clearstatcache;
use function count;
use function dirname;
use function escapeshellarg;
use function exec;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function implode;
use function is_dir;
use function mkdir;
use function realpath;
use function rmdir;
use function str_starts_with;
use function symlink;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Every case builds a throwaway git repository under the system temporary directory and drives the
 * manager against it; no real `claude` is started.
 *
 * @mago-expect lint:too-many-methods
 */
final class WorktreeManagerTest extends TestCase
{
    /** Each case gets its own tree, resolved so that it can be compared with what the manager returns. */
    private string $root = '';

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
     * The plain case, on a repository with no remote at all: rule 2 of the default branch resolution.
     *
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    #[Test]
    public function createsWorktreeFromTheCurrentBranchWhenThereIsNoRemote(): void
    {
        $repository = $this->initRepository('repo');
        [$originExitCode] = self::runGit($repository, ['symbolic-ref', 'refs/remotes/origin/HEAD']);
        self::assertNotSame(0, $originExitCode, 'This case is only meaningful without an origin/HEAD.');

        $path = self::manager($repository)->worktreeFor(new ThreadId('slack:1700000001.123456'));

        self::assertSame("{$repository}/.worktrees/slack-1700000001-123456", $path);
        self::assertTrue(str_starts_with($path, '/'), "\"{$path}\" must be absolute.");
        self::assertSame($path, realpath($path));
        self::assertDirectoryExists($path);
        self::assertSame('agent/slack-1700000001-123456', self::git($path, ['rev-parse', '--abbrev-ref', 'HEAD']));
        self::assertSame(self::git($repository, ['rev-parse', 'main']), self::git($path, ['rev-parse', 'HEAD']));
    }

    /**
     * Every call goes through the injected git, and prune comes before the add that needs it.
     *
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    #[Test]
    public function runsEveryGitCommandThroughTheInjectedImplementation(): void
    {
        $repository = $this->initRepository('repo');
        $git = new RecordingGit(new Git());

        $path = new WorktreeManager($repository, $git)->worktreeFor(new ThreadId('cli:x'));

        self::assertDirectoryExists($path);
        self::assertSame(
            [
                'worktree prune',
                'show-ref --verify --quiet refs/heads/agent/cli-x',
                'symbolic-ref refs/remotes/origin/HEAD',
                'symbolic-ref HEAD',
                "worktree add -b agent/cli-x {$path} main",
            ],
            $git->commands,
        );
    }

    /**
     * The disk is the whole state, so a second call has to observe what the first one left there.
     *
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    #[Test]
    public function reusesTheWorktreeOnTheSecondCall(): void
    {
        $repository = $this->initRepository('repo');
        $manager = self::manager($repository);
        $thread = new ThreadId('cli:x');

        $first = $manager->worktreeFor($thread);
        $before = self::worktreeCount($repository);
        $second = $manager->worktreeFor($thread);

        self::assertSame($first, $second);
        self::assertSame(2, $before, 'The base repository plus the one worktree.');
        self::assertSame($before, self::worktreeCount($repository));
    }

    /**
     * Two threads must not share a working tree; that separation is what the class exists for.
     *
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    #[Test]
    public function keepsTwoThreadsInSeparateWorktrees(): void
    {
        $repository = $this->initRepository('repo');
        $manager = self::manager($repository);

        $first = $manager->worktreeFor(new ThreadId('cli:one'));
        $second = $manager->worktreeFor(new ThreadId('slack:two'));

        self::assertNotSame($first, $second);
        self::assertDirectoryExists($first);
        self::assertDirectoryExists($second);

        $this->commit($first, 'only-in-first.txt');

        self::assertFileExists("{$first}/only-in-first.txt");
        self::assertFileDoesNotExist("{$second}/only-in-first.txt");
    }

    /**
     * The directory is gone but the registration and the branch are not: prune, then check the branch out again.
     *
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    #[Test]
    public function recoversAfterTheWorktreeDirectoryIsDeleted(): void
    {
        $repository = $this->initRepository('repo');
        $manager = self::manager($repository);
        $thread = new ThreadId('cli:x');

        $path = $manager->worktreeFor($thread);
        self::removeTree($path);

        self::assertDirectoryDoesNotExist($path);
        self::assertStringContainsString('prunable', self::git($repository, ['worktree', 'list']));
        [$branchExitCode] = self::runGit($repository, ['show-ref', '--verify', '--quiet', 'refs/heads/agent/cli-x']);
        self::assertSame(0, $branchExitCode, 'The branch outlives the directory; that is what recovery uses.');

        $again = $manager->worktreeFor($thread);

        self::assertSame($path, $again);
        self::assertDirectoryExists($again);
        self::assertSame('agent/cli-x', self::git($again, ['rev-parse', '--abbrev-ref', 'HEAD']));
    }

    /**
     * A remote-tracking ref is not the local branch, so the worktree is still cut from the default branch.
     *
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    #[Test]
    public function ignoresARemoteTrackingRefWithTheSameName(): void
    {
        $repository = $this->initRepository('repo');
        self::git($repository, ['checkout', '-b', 'side']);
        $this->commit($repository, 'side.txt');
        $sideTip = self::git($repository, ['rev-parse', 'side']);
        self::git($repository, ['checkout', 'main']);
        self::git($repository, ['branch', '-D', 'side']);
        self::git($repository, ['update-ref', 'refs/remotes/origin/agent/cli-x', $sideTip]);

        $path = self::manager($repository)->worktreeFor(new ThreadId('cli:x'));

        self::assertSame('agent/cli-x', self::git($path, ['rev-parse', '--abbrev-ref', 'HEAD']));
        self::assertSame(self::git($repository, ['rev-parse', 'main']), self::git($path, ['rev-parse', 'HEAD']));
        self::assertNotSame($sideTip, self::git($path, ['rev-parse', 'HEAD']));
    }

    /**
     * `git worktree add` failing is unrecoverable, and must not be swallowed into a path that is not there.
     *
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    #[Test]
    public function failsWhenTheBranchIsCheckedOutElsewhere(): void
    {
        $repository = $this->initRepository('repo');
        self::git($repository, ['checkout', '-b', 'agent/cli-x']);

        $this->expectException(WorktreeException::class);

        self::manager($repository)->worktreeFor(new ThreadId('cli:x'));
    }

    /**
     * Rule 1 wins over rule 2: the current branch is deliberately not the one origin/HEAD names.
     *
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    #[Test]
    public function prefersOriginHeadOverTheCurrentBranch(): void
    {
        $repository = $this->initRepository('repo');
        self::git($repository, ['checkout', '-b', 'feature']);
        $this->commit($repository, 'feature.txt');
        $mainTip = self::git($repository, ['rev-parse', 'main']);
        self::git($repository, ['update-ref', 'refs/remotes/origin/main', $mainTip]);
        self::git($repository, ['symbolic-ref', 'refs/remotes/origin/HEAD', 'refs/remotes/origin/main']);

        $path = self::manager($repository)->worktreeFor(new ThreadId('cli:x'));

        self::assertSame($mainTip, self::git($path, ['rev-parse', 'HEAD']));
        self::assertNotSame(self::git($repository, ['rev-parse', 'feature']), $mainTip);
    }

    /**
     * A ref carries the branch name after a known prefix, so the name may hold slashes of its own.
     *
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    #[DataProvider('defaultBranchRoutes')]
    #[Test]
    public function keepsSlashesInTheDefaultBranchName(string $route): void
    {
        $repository = $this->initRepository('repo');
        self::git($repository, ['checkout', '-b', 'release/1.0']);
        $this->commit($repository, 'release.txt');
        $releaseTip = self::git($repository, ['rev-parse', 'release/1.0']);

        if ($route === 'origin-head') {
            self::git($repository, ['update-ref', 'refs/remotes/origin/release/1.0', $releaseTip]);
            self::git($repository, ['symbolic-ref', 'refs/remotes/origin/HEAD', 'refs/remotes/origin/release/1.0']);
            self::git($repository, ['checkout', 'main']);
        }

        $path = self::manager($repository)->worktreeFor(new ThreadId('cli:x'));

        self::assertSame($releaseTip, self::git($path, ['rev-parse', 'HEAD']));
        self::assertNotSame(self::git($repository, ['rev-parse', 'main']), $releaseTip);
    }

    /** @return iterable<string, array{string}> */
    public static function defaultBranchRoutes(): iterable
    {
        yield 'via origin/HEAD' => ['origin-head'];
        yield 'via HEAD' => ['head'];
    }

    /**
     * An origin/HEAD pointing outside the remote namespace names no remote branch, so rule 2 answers.
     *
     * `git remote set-head` cannot write this, but `git symbolic-ref` can, and a repository that has
     * been through one is not broken enough for git itself to complain. Reading the name out of it
     * regardless would cut the worktree from a branch nobody asked for.
     *
     * The branch is deliberately longer than the prefix that does not match it: a shorter one is cut
     * to nothing by the length alone, so it would look the same whether the prefix was tested or not.
     *
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    #[Test]
    public function fallsBackToTheCurrentBranchWhenOriginHeadNamesSomethingElse(): void
    {
        $repository = $this->initRepository('repo');
        $branch = 'feature/with/a/rather/long/name';
        self::git($repository, ['checkout', '-b', $branch]);
        $this->commit($repository, 'feature.txt');
        self::git($repository, ['symbolic-ref', 'refs/remotes/origin/HEAD', "refs/heads/{$branch}"]);

        $git = new RecordingGit(new Git());
        $path = new WorktreeManager($repository, $git)->worktreeFor(new ThreadId('cli:x'));

        self::assertSame(self::git($repository, ['rev-parse', $branch]), self::git($path, ['rev-parse', 'HEAD']));
        self::assertNotSame(self::git($repository, ['rev-parse', 'main']), self::git($path, ['rev-parse', 'HEAD']));
        self::assertContains('symbolic-ref refs/remotes/origin/HEAD', $git->commands);
        self::assertContains('symbolic-ref HEAD', $git->commands, 'Rule 1 answered with a name it should not have.');
    }

    /**
     * A path that cannot be resolved and has no parent left ends the resolution rather than recursing.
     *
     * `realpath` gives up on a relative path once the working directory is gone, and `''` is its own
     * parent — which is the one shape that would have the resolution call itself forever. Deleting
     * the working directory is how that state is reached on both a BSD and a glibc `realpath`: the
     * BSD one answers an empty path with the working directory for as long as there is one.
     *
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    #[Test]
    public function stopsResolvingAPathThatHasNoParentLeft(): void
    {
        $previous = getcwd();
        self::assertIsString($previous);
        $gone = "{$this->root}/gone";
        self::assertTrue(mkdir($gone));
        $git = new StubGit();

        try {
            self::assertTrue(chdir($gone));
            self::assertTrue(rmdir($gone));
            clearstatcache(clear_realpath_cache: true);
            self::assertFalse(realpath(''), 'The working directory is still there; the case is not set up.');

            $path = new WorktreeManager('', $git)->worktreeFor(new ThreadId('cli:x'));
        } finally {
            self::assertTrue(chdir($previous));
        }

        self::assertSame('//.worktrees/cli-x', $path);
        self::assertSame(
            [
                'worktree prune',
                'show-ref --verify --quiet refs/heads/agent/cli-x',
                'worktree add //.worktrees/cli-x agent/cli-x',
            ],
            $git->commands,
        );
    }

    /**
     * Neither rule can name a branch, so there is nothing to cut the worktree from.
     *
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    #[Test]
    public function failsWhenHeadIsDetachedAndThereIsNoOriginHead(): void
    {
        $repository = $this->initRepository('repo');
        self::git($repository, ['checkout', '--detach', 'HEAD']);

        $this->expectException(WorktreeException::class);

        self::manager($repository)->worktreeFor(new ThreadId('cli:x'));
    }

    /**
     * Nothing can be derived from a directory that is not a repository at all.
     *
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    #[Test]
    public function failsWhenTheBaseIsNotARepository(): void
    {
        $plain = "{$this->root}/plain";
        self::assertTrue(mkdir($plain));

        $this->expectException(WorktreeException::class);

        self::manager($plain)->worktreeFor(new ThreadId('cli:x'));
    }

    /**
     * A symlinked `.worktrees` can put the worktree anywhere, so the check is on the resolved path.
     *
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    #[DataProvider('escapingTargets')]
    #[Test]
    public function refusesAPathThatResolvesOutsideTheBaseRepository(string $outside): void
    {
        $repository = $this->initRepository('repo');
        $target = "{$this->root}/{$outside}";
        self::assertTrue(mkdir($target));
        self::assertTrue(symlink($target, "{$repository}/.worktrees"));

        $this->expectException(WorktreeException::class);

        self::manager($repository)->worktreeFor(new ThreadId('cli:x'));
    }

    /** @return iterable<string, array{string}> */
    public static function escapingTargets(): iterable
    {
        yield 'a sibling directory' => ['outside'];
        // Shares the base repository path as a string prefix, so a prefix test that
        // forgets the separator would let this one through.
        yield 'a sibling whose name extends the base' => ['repo-evil'];
    }

    /**
     * A symlink is not itself the problem: this one still lands inside the base repository.
     *
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    #[Test]
    public function acceptsASymlinkThatStaysInsideTheBaseRepository(): void
    {
        $repository = $this->initRepository('repo');
        self::assertTrue(mkdir("{$repository}/nested"));
        self::assertTrue(symlink("{$repository}/nested", "{$repository}/.worktrees"));

        $path = self::manager($repository)->worktreeFor(new ThreadId('cli:x'));

        self::assertSame("{$repository}/nested/cli-x", $path);
        self::assertDirectoryExists($path);
    }

    /** The worktrees live inside the repository, so git has to be told to ignore them. */
    #[Test]
    public function thisRepositoryIgnoresTheWorktreeDirectory(): void
    {
        $projectRoot = dirname(__DIR__, levels: 2);
        $gitignore = file_get_contents("{$projectRoot}/.gitignore");
        self::assertIsString($gitignore);

        self::assertContains('.worktrees/', explode("\n", $gitignore));
    }

    /**
     * With the entry in place, creating a worktree leaves the base repository clean.
     *
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    #[Test]
    public function leavesTheBaseRepositoryClean(): void
    {
        $repository = $this->initRepository('repo');

        self::manager($repository)->worktreeFor(new ThreadId('cli:x'));

        $status = self::git($repository, ['status', '--porcelain']);

        self::assertSame('', $status);
        self::assertStringNotContainsString('.worktrees', $status);
    }

    /** The subject under test, wired to the git that actually runs the binary. */
    private static function manager(string $repository): WorktreeManager
    {
        return new WorktreeManager($repository, new Git());
    }

    /**
     * A throwaway repository with the initial branch and the identity pinned, so CI has nothing to supply.
     *
     * @return string the absolute path of the repository
     */
    private function initRepository(string $name): string
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
    private function commit(string $workingTree, string $file): void
    {
        $written = file_put_contents("{$workingTree}/{$file}", data: "content\n");
        self::assertIsInt($written);

        self::git($workingTree, ['add', '-A']);
        self::git($workingTree, ['commit', '-m', $file]);
    }

    /** How many working trees git knows about, the base repository included. */
    private static function worktreeCount(string $repository): int
    {
        return count(explode("\n", self::git($repository, ['worktree', 'list'])));
    }

    /** Deletes a directory without following the symlinks inside it. */
    private static function removeTree(string $path): void
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
    private static function git(string $cwd, array $arguments): string
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
    private static function runGit(string $cwd, array $arguments): array
    {
        $directory = escapeshellarg($cwd);
        $quoted = implode(' ', array_map(escapeshellarg(...), $arguments));

        $output = [];
        $exitCode = 0;
        exec("git -C {$directory} {$quoted} 2>&1", $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }
}
