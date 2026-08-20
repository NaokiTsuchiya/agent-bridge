<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Cli;

use NaokiTsuchiya\AgentBridge\Chat\StreamHandle;
use NaokiTsuchiya\AgentBridge\Support\RecordingStream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fclose;
use function fopen;
use function fwrite;
use function stream_get_contents;
use function uniqid;

/** The reply as a terminal gets it: whatever arrived, the moment it arrived. */
final class StandardOutputStreamTest extends TestCase
{
    /** A reply is one of the three ports, and this is the one that carries it. */
    #[Test]
    public function isAStreamHandle(): void
    {
        $stream = fopen('php://memory', mode: 'w+b');
        self::assertIsResource($stream);

        self::assertInstanceOf(StreamHandle::class, new StandardOutputStream($stream));

        fclose($stream);
    }

    /**
     * The point of the whole adapter: a fragment is out before the next one is known.
     *
     * Asserted between the two appends rather than after both, since a stream that held everything
     * back until the reply was over would look the same at the end.
     */
    #[Test]
    public function writesEachFragmentAsItArrives(): void
    {
        $stream = fopen('php://memory', mode: 'w+b');
        self::assertIsResource($stream);
        $reply = new StandardOutputStream($stream);

        $reply->append('one');
        self::assertSame('one', stream_get_contents($stream, offset: 0));

        $reply->append(' two');
        self::assertSame('one two', stream_get_contents($stream, offset: 0));

        fclose($stream);
    }

    /** Every append is its own write, which is what a reader sees arriving. */
    #[Test]
    public function keepsTheFragmentsApartOnTheWayOut(): void
    {
        $name = uniqid('reply-');
        $stream = RecordingStream::open($name);
        $reply = new StandardOutputStream($stream);

        $reply->append('one');
        $reply->append(' two');

        self::assertSame(['one', ' two'], RecordingStream::fragments($name));
    }

    /** A reader is left on a line of their own, whatever the agent ended with. */
    #[Test]
    public function endsTheReplyWithANewline(): void
    {
        $stream = fopen('php://memory', mode: 'w+b');
        self::assertIsResource($stream);
        $reply = new StandardOutputStream($stream);

        $reply->append('done');
        $reply->close();

        self::assertSame("done\n", stream_get_contents($stream, offset: 0));

        fclose($stream);
    }

    /**
     * The stream is the process's, and the next turn writes to it again.
     *
     * A handle that closed it would take standard output down with the first reply.
     */
    #[Test]
    public function leavesTheStreamItselfOpen(): void
    {
        $stream = fopen('php://memory', mode: 'w+b');
        self::assertIsResource($stream);

        new StandardOutputStream($stream)->close();

        self::assertIsInt(fwrite($stream, data: 'still usable'));
        self::assertSame("\nstill usable", stream_get_contents($stream, offset: 0));

        fclose($stream);
    }
}
