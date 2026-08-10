<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Thread;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ThreadIdTest extends TestCase
{
    /** @throws InvalidArgumentException */
    #[Test]
    public function exposesPlatformAndNativeIdSeparately(): void
    {
        $thread = new ThreadId('cli:my-experiment');

        self::assertSame('cli', $thread->platform);
        self::assertSame('my-experiment', $thread->nativeId);
        self::assertSame('cli:my-experiment', $thread->value);
    }

    /** @throws InvalidArgumentException */
    #[Test]
    public function splitsOnTheFirstColonOnly(): void
    {
        $thread = new ThreadId('slack:C123:456');

        self::assertSame('slack', $thread->platform);
        self::assertSame('C123:456', $thread->nativeId);
    }

    /** @throws InvalidArgumentException */
    #[Test]
    public function acceptsDotDotInPlatformBecauseSlashIsAlreadyRejected(): void
    {
        $thread = new ThreadId('a..b:x');

        self::assertSame('a..b', $thread->platform);
        self::assertSame('x', $thread->nativeId);
    }

    /** @throws InvalidArgumentException */
    #[DataProvider('invalidThreadIds')]
    #[Test]
    public function rejectsInvalidThreadId(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ThreadId($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidThreadIds(): iterable
    {
        yield 'no colon' => ['cli'];
        yield 'empty string' => [''];
        yield 'empty platform' => [':my-experiment'];
        yield 'empty native id' => ['cli:'];
        yield 'slash in platform' => ['c/li:x'];
        yield 'slash in native id' => ['cli:a/b'];
        yield 'dot dot inside native id' => ['cli:a..b'];
        yield 'native id is exactly dot dot' => ['cli:..'];
    }
}
