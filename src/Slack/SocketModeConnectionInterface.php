<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

/**
 * One live Socket Mode connection, reduced to the three moves the client makes on it.
 *
 * The seam exists so that reconnection, silence and duplicate delivery can be driven without a real
 * WebSocket; see `docs/slack-socket-mode.md` for what only a real workspace can show.
 *
 * @api
 */
interface SocketModeConnectionInterface
{
    /**
     * The next frame's text, or null when nothing arrived within the timeout.
     *
     * Silence is a value rather than an exception because the client answers it by discarding the
     * connection, which is a normal outcome rather than a failure.
     *
     * @throws SocketModeException when the connection is no longer usable
     */
    public function receive(float $timeout): ?string;

    /**
     * Sends one text frame, used for acknowledgements.
     *
     * @throws SocketModeException when the connection is no longer usable
     */
    public function send(string $payload): void;

    /** Releases the connection; calling it twice is not an error. */
    public function close(): void;
}
