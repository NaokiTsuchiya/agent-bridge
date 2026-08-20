<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Cli;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Chat\ChatEgress;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fopen;
use function stream_get_contents;

/**
 * Which of the two streams each kind of output lands on.
 *
 * The separation is the whole reason a terminal can be piped: an answer redirected into a file
 * must be the answer, and nothing else the reader was told along the way.
 */
final class StandardOutputEgressTest extends TestCase
{
    /** The thread every case uses; none of them is about which one it is. */
    private const string THREAD = 'cli:my-experiment';

    /** It is the way out, whichever front end is asked for one. */
    #[Test]
    public function isAChatEgress(): void
    {
        self::assertInstanceOf(ChatEgress::class, new StandardOutputEgress(self::memory(), self::memory()));
    }

    /**
     * A status is shown where it cannot end up inside a redirected answer.
     *
     * @throws InvalidArgumentException
     */
    #[Test]
    public function keepsAStatusOutOfTheAnswer(): void
    {
        $reply = self::memory();
        $status = self::memory();

        new StandardOutputEgress($reply, $status)->status(new ThreadId(self::THREAD), 'Working on it.');

        self::assertSame("# Working on it.\n", stream_get_contents($status, offset: 0));
        self::assertSame('', stream_get_contents($reply, offset: 0));
    }

    /**
     * The answer goes to the answer's stream, and the reader who redirected it gets only that.
     *
     * @throws InvalidArgumentException
     */
    #[Test]
    public function writesTheReplyToTheReplyStream(): void
    {
        $reply = self::memory();
        $status = self::memory();

        $answer = new StandardOutputEgress($reply, $status)->open(new ThreadId(self::THREAD));
        $answer->append('an answer');
        $answer->close();

        self::assertSame("an answer\n", stream_get_contents($reply, offset: 0));
        self::assertSame('', stream_get_contents($status, offset: 0));
    }

    /**
     * Each turn is written as its own reply; one handle held across turns would be a shared
     * position on a stream that two turns could interleave on.
     *
     * @throws InvalidArgumentException
     */
    #[Test]
    public function opensAReplyPerTurn(): void
    {
        $egress = new StandardOutputEgress(self::memory(), self::memory());
        $thread = new ThreadId(self::THREAD);

        self::assertNotSame($egress->open($thread), $egress->open($thread));
    }

    /** @return resource a stream that keeps what was written to it, for the case to read back */
    private static function memory(): mixed
    {
        $stream = fopen('php://memory', mode: 'w+b');
        self::assertIsResource($stream);

        return $stream;
    }
}
