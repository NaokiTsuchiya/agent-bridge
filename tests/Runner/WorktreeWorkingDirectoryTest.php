<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Runner;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Git\Git;
use NaokiTsuchiya\AgentBridge\Runner\WorktreeWorkingDirectory;
use NaokiTsuchiya\AgentBridge\Tests\Support\TempDir;
use NaokiTsuchiya\AgentBridge\Tests\Worktree\RecordingGit;
use NaokiTsuchiya\AgentBridge\Thread\ThreadDerivation;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use NaokiTsuchiya\AgentBridge\Worktree\WorktreeException;
use NaokiTsuchiya\AgentBridge\Worktree\WorktreeManager;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function mkdir;
use function realpath;

/**
 * The seam that tells the runner where to start a thread's process.
 *
 * Driven against a real manager over a throwaway directory whose worktree is already there, so that
 * the case is about the delegation rather than about git.
 */
final class WorktreeWorkingDirectoryTest extends TestCase
{
    /** The throwaway repository the worktrees are cut from. */
    private string $repository = '';

    /** Each case cuts its worktrees from a repository of its own. */
    #[Override]
    protected function setUp(): void
    {
        $this->repository = TempDir::make('working-directory');
    }

    /** Leaves nothing behind under the system temporary directory. */
    #[Override]
    protected function tearDown(): void
    {
        TempDir::remove($this->repository);
    }

    /**
     * The runner must not have to know how this project lays out worktrees, which is the whole
     * reason the resolver is a seam of its own.
     *
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    #[Test]
    public function answersWithTheThreadsWorktree(): void
    {
        $thread = new ThreadId('slack:C123.456');
        $worktree = $this->repository . '/' . ThreadDerivation::worktreePath($thread);
        self::assertTrue(mkdir($worktree, permissions: 0o755, recursive: true));
        $git = new RecordingGit(new Git());

        $resolved = new WorktreeWorkingDirectory(new WorktreeManager($this->repository, $git))->resolve($thread);

        self::assertSame(realpath($worktree), $resolved);
        self::assertSame([], $git->commands, message: 'An existing worktree needs no git.');
    }
}
