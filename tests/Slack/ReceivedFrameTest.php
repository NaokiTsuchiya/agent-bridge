<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use NaokiTsuchiya\AgentBridge\Slack\FrameOutcome;
use NaokiTsuchiya\AgentBridge\Slack\ReceivedFrame;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\WebSocket\CloseFrame;
use Swoole\WebSocket\Frame;

use const SWOOLE_WEBSOCKET_OPCODE_BINARY;
use const SWOOLE_WEBSOCKET_OPCODE_PING;
use const SWOOLE_WEBSOCKET_OPCODE_PONG;
use const SWOOLE_WEBSOCKET_OPCODE_TEXT;

/**
 * What each shape of `recv()` return means, decided without a socket.
 *
 * The frames are built by hand, which is all it takes: they are plain objects, and the classifier
 * takes the return value and the `connected` flag rather than the client they came from.
 *
 * @mago-expect lint:too-many-methods
 *
 * @internal
 */
final class ReceivedFrameTest extends TestCase
{
    /** The ordinary case: a text frame is what the router parses. */
    #[Test]
    public function readsATextFrame(): void
    {
        $frame = ReceivedFrame::of(self::frame(SWOOLE_WEBSOCKET_OPCODE_TEXT, '{"type":"hello"}'), connected: true);

        self::assertSame(FrameOutcome::Text, $frame->outcome);
        self::assertSame('{"type":"hello"}', $frame->text);
    }

    /** An empty text frame is still a frame; whether its content makes sense is the router's business. */
    #[Test]
    public function readsAnEmptyTextFrameAsText(): void
    {
        $frame = ReceivedFrame::of(self::frame(SWOOLE_WEBSOCKET_OPCODE_TEXT, ''), connected: true);

        self::assertSame(FrameOutcome::Text, $frame->outcome);
        self::assertSame('', $frame->text);
    }

    /** A ping is traffic that has to be answered; calling it silence would drop a working connection. */
    #[Test]
    public function readsAPingAsSomethingToAnswer(): void
    {
        $frame = ReceivedFrame::of(self::frame(SWOOLE_WEBSOCKET_OPCODE_PING, ''), connected: true);

        self::assertSame(FrameOutcome::Ping, $frame->outcome);
    }

    /** A pong or a binary frame says nothing, but it proves the connection is alive. */
    #[Test]
    public function readsAPongAndABinaryFrameAsTrafficToIgnore(): void
    {
        $pong = ReceivedFrame::of(self::frame(SWOOLE_WEBSOCKET_OPCODE_PONG, ''), connected: true);
        $binary = ReceivedFrame::of(self::frame(SWOOLE_WEBSOCKET_OPCODE_BINARY, "\x00\x01"), connected: true);

        self::assertSame(FrameOutcome::Ignored, $pong->outcome);
        self::assertSame(FrameOutcome::Ignored, $binary->outcome);
    }

    /** An orderly close is the end of this connection, not a quiet moment on it. */
    #[Test]
    public function readsACloseFrameAsAClosedConnection(): void
    {
        self::assertSame(FrameOutcome::Closed, ReceivedFrame::of(new CloseFrame(), connected: true)->outcome);
    }

    /** `false` on a connection that is still up is the timeout the silence handling is built on. */
    #[Test]
    public function readsATimeoutOnALiveConnectionAsSilence(): void
    {
        self::assertSame(FrameOutcome::Silence, ReceivedFrame::of(false, connected: true)->outcome);
    }

    /** The same `false` on a connection that is gone is a break, and has to cost the connection. */
    #[Test]
    public function readsAFailureOnADeadConnectionAsBroken(): void
    {
        self::assertSame(FrameOutcome::Broken, ReceivedFrame::of(false, connected: false)->outcome);
    }

    /** An empty string is what a closed socket reads as; it is not an empty frame. */
    #[Test]
    public function readsAnEmptyStringAsBroken(): void
    {
        self::assertSame(FrameOutcome::Broken, ReceivedFrame::of('', connected: true)->outcome);
        self::assertSame(FrameOutcome::Broken, ReceivedFrame::of('', connected: false)->outcome);
    }

    /** Newer Swoole can answer with the payload itself rather than a frame object. */
    #[Test]
    public function readsARawStringAsText(): void
    {
        $frame = ReceivedFrame::of('{"type":"disconnect"}', connected: true);

        self::assertSame(FrameOutcome::Text, $frame->outcome);
        self::assertSame('{"type":"disconnect"}', $frame->text);
    }

    /** `true` is not something `recv()` promises; whatever it would mean, it carries no frame. */
    #[Test]
    public function readsATruthyNonFrameAsBroken(): void
    {
        self::assertSame(FrameOutcome::Broken, ReceivedFrame::of(true, connected: true)->outcome);
    }

    /** A frame as Swoole hands it over: a plain object with the opcode and the payload set. */
    private static function frame(int $opcode, string $data): Frame
    {
        $frame = new Frame();
        $frame->opcode = $opcode;
        $frame->data = $data;

        return $frame;
    }
}
