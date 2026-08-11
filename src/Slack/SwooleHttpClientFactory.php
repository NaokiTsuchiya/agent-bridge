<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Override;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\Http\Client\Exception as ClientException;

/**
 * Coroutine HTTP clients over TLS, with the socket timeout every one of them gets.
 *
 * @api
 */
final class SwooleHttpClientFactory implements HttpClientFactoryInterface
{
    /**
     * @param float $timeout the ceiling on a single socket operation, in seconds; Slack refreshes a
     *                       Socket Mode connection every few hours, so this only has to outlast the
     *                       keepalives, and the recv loop passes its own deadline to each receive
     */
    public function __construct(
        private float $timeout = 60.0,
    ) {}

    /** @throws SocketModeException */
    #[Override]
    public function create(string $host, int $port): Client
    {
        try {
            // Always TLS: both endpoints Slack hands out are https/wss, and the connection ticket
            // in the WSS URL is a credential that must not go out in the clear.
            $client = new Client($host, $port, ssl: true);
        } catch (ClientException $exception) {
            throw new SocketModeException("Cannot open a client for {$host}: {$exception->getMessage()}");
        }

        $client->set(['timeout' => $this->timeout]);

        return $client;
    }
}
