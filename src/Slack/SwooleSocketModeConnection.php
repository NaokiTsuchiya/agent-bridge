<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Override;
use Swoole\Coroutine\Http\Client;

use function microtime;

use const SWOOLE_WEBSOCKET_OPCODE_PONG;

/**
 * A live connection on a Swoole coroutine HTTP client.
 *
 * Every judgement it would otherwise make is delegated to {@see ReceivedFrame}; what is left is the
 * I/O that only a real workspace can exercise (`docs/slack-socket-mode.md`).
 *
 * @api
 */
final class SwooleSocketModeConnection implements SocketModeConnectionInterface
{
    /** @param Client $client already upgraded; this class never speaks HTTP on it */
    public function __construct(
        private Client $client,
    ) {}

    /**
     * Receives until the deadline rather than until the first frame, so that a keepalive exchange
     * does not read as silence and cost the connection.
     *
     * @throws SocketModeException
     */
    #[Override]
    public function receive(float $timeout): ?string
    {
        $deadline = microtime(as_float: true) + $timeout;

        while (true) {
            $remaining = $deadline - microtime(as_float: true);

            // Swoole reads a timeout of zero or less as "wait forever", which is the one thing the
            // recv loop must never do, so the deadline is checked before the call and not after.
            if ($remaining <= 0.0) {
                return null;
            }

            $frame = ReceivedFrame::of($this->client->recv($remaining), $this->client->connected);

            if ($frame->outcome === FrameOutcome::Text) {
                return $frame->text;
            }

            if ($frame->outcome === FrameOutcome::Silence) {
                return null;
            }

            if ($frame->outcome === FrameOutcome::Closed) {
                throw new SocketModeException('Slack closed the Socket Mode connection.');
            }

            if ($frame->outcome === FrameOutcome::Broken) {
                throw new SocketModeException("The Socket Mode connection is gone: {$this->client->errMsg}");
            }

            if ($frame->outcome === FrameOutcome::Ping) {
                $this->client->push('', SWOOLE_WEBSOCKET_OPCODE_PONG);
            }
        }
    }

    /** @throws SocketModeException */
    #[Override]
    public function send(string $payload): void
    {
        $sent = $this->client->push($payload);

        if (!$sent) {
            throw new SocketModeException("Cannot send on the Socket Mode connection: {$this->client->errMsg}");
        }
    }

    /** Closing an already closed client is answered with false, which is not worth reporting. */
    #[Override]
    public function close(): void
    {
        $this->client->close();
    }
}
