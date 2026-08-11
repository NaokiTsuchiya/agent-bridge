<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Pipeline;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Git\Git;
use NaokiTsuchiya\AgentBridge\Pipeline\ResolvedThread;
use NaokiTsuchiya\AgentBridge\Tests\Support\GitRepository;
use NaokiTsuchiya\AgentBridge\Thread\ThreadIdFactory;
use NaokiTsuchiya\AgentBridge\Worktree\WorktreeException;
use NaokiTsuchiya\AgentBridge\Worktree\WorktreeManager;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The middle of the chain on its own: what has to be true for one of these to exist at all.
 *
 * Built directly rather than through Be, so that the state is looked at the moment the constructor
 * returns — before anything downstream has had a chance to make the worktree itself.
 */
final class ResolvedThreadTest extends TestCase
{
    /** The repository the worktrees are cut from. */
    private string $repository = '';

    /** {@inheritDoc} */
    #[Override]
    protected function setUp(): void
    {
        $this->repository = GitRepository::make('resolved-thread');
    }

    /** {@inheritDoc} */
    #[Override]
    protected function tearDown(): void
    {
        GitRepository::remove($this->repository);
    }

    /**
     * The directory is there as soon as the object is, which is the whole claim of this stage.
     *
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    #[Test]
    public function createsTheWorktree(): void
    {
        $resolved = $this->resolve('cli', 'my-experiment');

        self::assertSame("{$this->repository}/.worktrees/cli-my-experiment", $resolved->worktree);
        self::assertDirectoryExists($resolved->worktree);
    }

    /**
     * The session id is #3's, checked against the vectors of docs/poc-design.md rather than against
     * whatever the code currently computes — a derivation written a second time here would pass a
     * comparison with itself.
     *
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    #[DataProvider('knownVectors')]
    #[Test]
    public function derivesTheSessionIdOfTheVector(string $platform, string $nativeId, string $sessionId): void
    {
        $resolved = $this->resolve($platform, $nativeId);

        self::assertSame($sessionId, $resolved->sessionId);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function knownVectors(): iterable
    {
        yield 'cli' => ['cli', 'my-experiment', 'b0f400e4-b88d-5d39-a7ee-6cd49fbc4b39'];
        yield 'slack' => ['slack', '1700000001.123456', '959a94a6-5395-5d07-bc71-0a0c7d800476'];
        yield 'discord' => ['discord', '1234567890123456789', '69f77640-5a3a-5c50-b568-e888871d9b10'];
    }

    /**
     * A second message on the same thread lands in the directory the first one made.
     *
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    #[Test]
    public function keepsTheWorktreeOfAThreadItHasSeenBefore(): void
    {
        $first = $this->resolve('cli', 'my-experiment');
        $second = $this->resolve('cli', 'my-experiment');

        self::assertSame($first->worktree, $second->worktree);
        self::assertDirectoryExists($second->worktree);
    }

    /**
     * The message is carried on untouched; only the thread is worked out here.
     *
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    #[Test]
    public function carriesTheMessageOn(): void
    {
        $resolved = $this->resolve('cli', 'my-experiment', 'what does this repository do?');

        self::assertSame('what does this repository do?', $resolved->text);
        self::assertSame('cli:my-experiment', $resolved->thread->value);
    }

    /**
     * @throws InvalidArgumentException
     * @throws WorktreeException
     */
    private function resolve(string $platform, string $nativeId, string $text = 'hello'): ResolvedThread
    {
        return new ResolvedThread(
            $platform,
            $nativeId,
            $text,
            new ThreadIdFactory(),
            new WorktreeManager($this->repository, new Git()),
        );
    }
}
