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
use function symlink;

final class WorktreeManagerPathGuardTest extends WorktreeTestCase
{
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

        new WorktreeManager($repository)->worktreeFor(new ThreadId('cli:x'));
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

        $path = new WorktreeManager($repository)->worktreeFor(new ThreadId('cli:x'));

        self::assertSame("{$repository}/nested/cli-x", $path);
        self::assertDirectoryExists($path);
    }
}
