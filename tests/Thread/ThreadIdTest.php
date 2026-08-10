<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Thread;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ThreadIdTest extends TestCase
{
    /** @throws InvalidArgumentException */
    public function testExposesPlatformAndNativeIdSeparately(): void
    {
        $thread = new ThreadId('cli:my-experiment');

        static::assertSame('cli', $thread->platform);
        static::assertSame('my-experiment', $thread->nativeId);
        static::assertSame('cli:my-experiment', $thread->value);
    }

    /** @throws InvalidArgumentException */
    public function testSplitsOnTheFirstColonOnly(): void
    {
        $thread = new ThreadId('slack:C123:456');

        static::assertSame('slack', $thread->platform);
        static::assertSame('C123:456', $thread->nativeId);
    }

    /** @throws InvalidArgumentException */
    public function testAcceptsDotDotInPlatformBecauseSlashIsAlreadyRejected(): void
    {
        $thread = new ThreadId('a..b:x');

        static::assertSame('a..b', $thread->platform);
        static::assertSame('x', $thread->nativeId);
    }

    /** @throws InvalidArgumentException */
    #[DataProvider('invalidThreadIds')]
    public function testRejectsInvalidThreadId(string $value): void
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
