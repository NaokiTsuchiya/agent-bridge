<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Thread;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Thread\ThreadDerivation;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ThreadDerivationTest extends TestCase
{
    public function testNamespaceUuidIsTheAgreedConstant(): void
    {
        self::assertSame('33adc75c-ded9-51f3-b48f-fe0eebd1fcbf', ThreadDerivation::NAMESPACE_UUID);
    }

    /** @throws InvalidArgumentException */
    #[DataProvider('knownVectors')]
    public function testMatchesKnownVector(string $value, string $sessionId, string $slug): void
    {
        $thread = new ThreadId($value);

        self::assertSame($sessionId, ThreadDerivation::sessionId($thread));
        self::assertSame($slug, ThreadDerivation::slug($thread));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function knownVectors(): iterable
    {
        yield 'cli' => ['cli:my-experiment', 'b0f400e4-b88d-5d39-a7ee-6cd49fbc4b39', 'cli-my-experiment'];
        yield 'slack' => ['slack:1700000001.123456', '959a94a6-5395-5d07-bc71-0a0c7d800476', 'slack-1700000001-123456'];
        yield 'discord' => [
            'discord:1234567890123456789',
            '69f77640-5a3a-5c50-b568-e888871d9b10',
            'discord-1234567890123456789',
        ];
    }

    /** @throws InvalidArgumentException */
    public function testDerivesWorktreePathAndBranchName(): void
    {
        $thread = new ThreadId('slack:1700000001.123456');

        self::assertSame('.worktrees/slack-1700000001-123456', ThreadDerivation::worktreePath($thread));
        self::assertSame('agent/slack-1700000001-123456', ThreadDerivation::branchName($thread));
    }

    /** @throws InvalidArgumentException */
    public function testReplacesEveryColonNotJustTheFirst(): void
    {
        $thread = new ThreadId('slack:C123:456');

        self::assertSame('slack-C123-456', ThreadDerivation::slug($thread));
        self::assertSame('.worktrees/slack-C123-456', ThreadDerivation::worktreePath($thread));
        self::assertSame('agent/slack-C123-456', ThreadDerivation::branchName($thread));
    }

    /** @throws InvalidArgumentException */
    public function testReplacesDotsOnThePlatformSideToo(): void
    {
        self::assertSame('a-b-x', ThreadDerivation::slug(new ThreadId('a.b:x')));
        self::assertSame('a--b-x', ThreadDerivation::slug(new ThreadId('a..b:x')));
    }

    /** @throws InvalidArgumentException */
    public function testDerivingTwiceReturnsTheSameValues(): void
    {
        $thread = new ThreadId('slack:1700000001.123456');

        self::assertSame(ThreadDerivation::sessionId($thread), ThreadDerivation::sessionId($thread));
        self::assertSame(ThreadDerivation::slug($thread), ThreadDerivation::slug($thread));
        self::assertSame(ThreadDerivation::worktreePath($thread), ThreadDerivation::worktreePath($thread));
        self::assertSame(ThreadDerivation::branchName($thread), ThreadDerivation::branchName($thread));

        $again = new ThreadId('slack:1700000001.123456');

        self::assertSame(ThreadDerivation::sessionId($thread), ThreadDerivation::sessionId($again));
        self::assertSame(ThreadDerivation::branchName($thread), ThreadDerivation::branchName($again));
    }
}
