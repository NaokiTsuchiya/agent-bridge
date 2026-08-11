<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

/**
 * The Socket Mode connection loop: connect, read frames until the connection dies, reconnect.
 *
 * Slack cycles connections on purpose, so reconnection is the normal path rather than the error
 * path. Nothing here touches Swoole: the connection, the waiting and the randomness are injected,
 * which is what lets every one of those paths be driven without Slack.
 *
 * @api
 */
final class SocketModeClient
{
    /** Turned off by {@see stop()}; checked between frames so that a shutdown does not wait for one. */
    private bool $running = false;

    /** @param float $silenceTimeout how long a connection may produce nothing before it is discarded */
    public function __construct(
        private SocketModeConnectorInterface $connector,
        private FrameRouter $router,
        private ReconnectDelay $delay,
        private SocketModeLoggerInterface $logger,
        private float $silenceTimeout = 60.0,
    ) {}

    /** Runs until {@see stop()}. Losing a connection is not an error here, only a reason to wait. */
    public function run(): void
    {
        $this->running = true;
        $attempt = 0;

        while ($this->running) {
            try {
                $connection = $this->connector->connect();
            } catch (SocketModeException $exception) {
                $attempt++;
                $this->logger->log("cannot connect: {$exception->getMessage()}");
                $this->pause($attempt);

                continue;
            }

            $this->consume($connection);
            // A connection that was established resets the ladder: a routine disconnect says
            // nothing about Slack being unreachable, so the next wait is the shortest one.
            $attempt = 1;
            $this->pause($attempt);
        }
    }

    /** Asks the loop to finish; it returns after the frame it is on, without opening a connection. */
    public function stop(): void
    {
        $this->running = false;
    }

    /** Reads frames until the connection is done with, then always releases it. */
    private function consume(SocketModeConnectionInterface $connection): void
    {
        try {
            while ($this->running) {
                $keepReading = $this->step($connection);

                if (!$keepReading) {
                    break;
                }
            }
        } catch (SocketModeException $exception) {
            $this->logger->log("connection lost: {$exception->getMessage()}");
        }

        $connection->close();
    }

    /**
     * Reads one frame, answering whether this connection is still worth reading.
     *
     * @throws SocketModeException
     */
    private function step(SocketModeConnectionInterface $connection): bool
    {
        $frame = $connection->receive($this->silenceTimeout);

        if ($frame === null) {
            $this->logger->log("nothing arrived within {$this->silenceTimeout}s; reconnecting");

            return false;
        }

        return $this->router->route($frame, $connection);
    }

    /** Waits out the backoff, unless a shutdown was asked for while the connection was up. */
    private function pause(int $attempt): void
    {
        if (!$this->running) {
            return;
        }

        $this->delay->waitBefore($attempt);
    }
}
