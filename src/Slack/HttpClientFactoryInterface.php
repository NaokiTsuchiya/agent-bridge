<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Swoole\Coroutine\Http\Client;

/**
 * Where a coroutine HTTP client comes from.
 *
 * The connector needs two of them — one for the API call, one for the WebSocket — and creating them
 * is not what it is about; this is the seam that keeps that decision (TLS, proxies, socket options)
 * outside it.
 *
 * @api
 */
interface HttpClientFactoryInterface
{
    /**
     * A client aimed at the given host, not yet connected.
     *
     * @throws SocketModeException when a client cannot be created at all
     */
    public function create(string $host, int $port): Client;
}
