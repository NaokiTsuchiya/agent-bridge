<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Thread;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ThreadIdFactoryTest extends TestCase
{
    /**
     * The two parts are joined by exactly one separator and nothing else is done to them.
     *
     * This is also why a thread id with no separator at all cannot reach the pipeline: a message
     * carries the parts, and they are always joined this way.
     *
     * @throws InvalidArgumentException
     */
    #[Test]
    public function joinsThePartsWithOneSeparator(): void
    {
        $thread = new ThreadIdFactory()->fromParts('cli', 'my-experiment');

        self::assertSame('cli:my-experiment', $thread->value);
        self::assertSame('cli', $thread->platform);
        self::assertSame('my-experiment', $thread->nativeId);
    }

    /**
     * A native id may carry separators of its own, and keeps them.
     *
     * @throws InvalidArgumentException
     */
    #[Test]
    public function leavesTheNativeIdsOwnColonsAlone(): void
    {
        $thread = new ThreadIdFactory()->fromParts('slack', 'C123:456');

        self::assertSame('slack:C123:456', $thread->value);
        self::assertSame('slack', $thread->platform);
        self::assertSame('C123:456', $thread->nativeId);
    }

    /**
     * `..` on the platform side is not a way out of anything, because a platform cannot carry a
     * slash; refusing it here would refuse a thread the rest of the application accepts.
     *
     * @throws InvalidArgumentException
     */
    #[Test]
    public function acceptsDotDotInThePlatform(): void
    {
        $thread = new ThreadIdFactory()->fromParts('a..b', 'x');

        self::assertSame('a..b', $thread->platform);
        self::assertSame('x', $thread->nativeId);
    }

    /**
     * The one rule that is this class's own: a platform carrying a separator would end up naming
     * a different platform, and with it a different session and a different worktree.
     *
     * @throws InvalidArgumentException
     */
    #[Test]
    public function rejectsASeparatorInThePlatform(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ThreadIdFactory()->fromParts('a:b', 'x');
    }

    /**
     * Everything else is the value object's judgement, which this must not soften.
     *
     * @throws InvalidArgumentException
     */
    #[DataProvider('rejectedParts')]
    #[Test]
    public function passesOnWhatAThreadIdRefuses(string $platform, string $nativeId): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ThreadIdFactory()->fromParts($platform, $nativeId);
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejectedParts(): iterable
    {
        yield 'empty platform' => ['', 'x'];
        yield 'empty native id' => ['cli', ''];
        yield 'both empty' => ['', ''];
        yield 'slash in the platform' => ['c/li', 'x'];
        yield 'slash in the native id' => ['cli', 'a/b'];
        yield 'dot dot in the native id' => ['cli', 'a..b'];
        yield 'native id is exactly dot dot' => ['cli', '..'];
    }
}
