<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Swoole\WebSocket\Frame;

use function is_string;

use const SWOOLE_WEBSOCKET_OPCODE_CLOSE;
use const SWOOLE_WEBSOCKET_OPCODE_PING;
use const SWOOLE_WEBSOCKET_OPCODE_TEXT;

/**
 * What `Swoole\Coroutine\Http\Client::recv()` returned, classified.
 *
 * `recv()` answers with a frame, `false` or a raw string depending on what happened, and the
 * classification decides whether the client keeps receiving, reconnects, or hands the text on. It
 * takes the return value and the client's `connected` flag rather than the client itself, so the
 * whole judgement can be exercised without a socket.
 *
 * @api
 */
final class ReceivedFrame
{
    /** @param string $text empty for every outcome but {@see FrameOutcome::Text} */
    private function __construct(
        public FrameOutcome $outcome,
        public string $text,
    ) {}

    /**
     * @param bool $connected the client's `connected` property, read right after `recv()` returned
     *
     * @mago-expect lint:no-boolean-flag-parameter
     *   Not a mode switch: it is the second half of what `recv()` reported, and the pair is what
     *   tells a timeout apart from a dead socket.
     */
    public static function of(Frame|bool|string $received, bool $connected): self
    {
        if ($received instanceof Frame) {
            return match ($received->opcode) {
                SWOOLE_WEBSOCKET_OPCODE_TEXT => new self(FrameOutcome::Text, $received->data),
                SWOOLE_WEBSOCKET_OPCODE_PING => new self(FrameOutcome::Ping, ''),
                SWOOLE_WEBSOCKET_OPCODE_CLOSE => new self(FrameOutcome::Closed, ''),
                // A pong or a binary frame says nothing to the client, but it is traffic: treating
                // it as silence would drop a connection that is working.
                default => new self(FrameOutcome::Ignored, ''),
            };
        }

        if (is_string($received)) {
            return $received === '' ? new self(FrameOutcome::Broken, '') : new self(FrameOutcome::Text, $received);
        }

        // `false` means both "nothing arrived in time" and "the socket is gone". errno would tell
        // them apart, but ETIMEDOUT differs per platform (60 on macOS, 110 on Linux) and comes from
        // ext-sockets, which the CI image does not install; `connected` is on the client itself.
        return !$received && $connected ? new self(FrameOutcome::Silence, '') : new self(FrameOutcome::Broken, '');
    }
}
