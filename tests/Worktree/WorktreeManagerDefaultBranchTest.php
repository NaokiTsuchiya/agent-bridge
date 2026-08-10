<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Worktree;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use NaokiTsuchiya\AgentBridge\Worktree\WorktreeException;
use NaokiTsuchiya\AgentBridge\Worktree\WorktreeManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function mkdir;

final class WorktreeManagerDefaultBranchTest extends WorktreeTestCase
{
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

        $path = new WorktreeManager($repository)->worktreeFor(new ThreadId('cli:x'));

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

        $path = new WorktreeManager($repository)->worktreeFor(new ThreadId('cli:x'));

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

        new WorktreeManager($repository)->worktreeFor(new ThreadId('cli:x'));
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

        new WorktreeManager($plain)->worktreeFor(new ThreadId('cli:x'));
    }
}
