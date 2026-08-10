<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Worktree;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use NaokiTsuchiya\AgentBridge\Worktree\WorktreeException;
use NaokiTsuchiya\AgentBridge\Worktree\WorktreeManager;
use PHPUnit\Framework\Attributes\Test;

use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function realpath;
use function str_starts_with;

final class WorktreeManagerTest extends WorktreeTestCase
{
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

        $path = new WorktreeManager($repository)->worktreeFor(new ThreadId('slack:1700000001.123456'));

        self::assertSame("{$repository}/.worktrees/slack-1700000001-123456", $path);
        self::assertTrue(str_starts_with($path, '/'), "\"{$path}\" must be absolute.");
        self::assertSame($path, realpath($path));
        self::assertDirectoryExists($path);
        self::assertSame('agent/slack-1700000001-123456', self::git($path, ['rev-parse', '--abbrev-ref', 'HEAD']));
        self::assertSame(self::git($repository, ['rev-parse', 'main']), self::git($path, ['rev-parse', 'HEAD']));
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
        $manager = new WorktreeManager($repository);
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
        $manager = new WorktreeManager($repository);

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
        $manager = new WorktreeManager($repository);
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

        $path = new WorktreeManager($repository)->worktreeFor(new ThreadId('cli:x'));

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

        new WorktreeManager($repository)->worktreeFor(new ThreadId('cli:x'));
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

        new WorktreeManager($repository)->worktreeFor(new ThreadId('cli:x'));

        $status = self::git($repository, ['status', '--porcelain']);

        self::assertSame('', $status);
        self::assertStringNotContainsString('.worktrees', $status);
    }

    /** How many working trees git knows about, the base repository included. */
    private static function worktreeCount(string $repository): int
    {
        return count(explode("\n", self::git($repository, ['worktree', 'list'])));
    }
}
