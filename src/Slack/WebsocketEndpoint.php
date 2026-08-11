<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use function is_int;
use function is_string;
use function parse_url;

/**
 * The pieces of a WSS URL that a coroutine HTTP client is opened with.
 *
 * `Swoole\Coroutine\Http\Client` takes host, port and path separately rather than a URL, so the
 * split has to happen somewhere; here it happens where it can be tested without a socket.
 *
 * @api
 */
final class WebsocketEndpoint
{
    /** WebSocket over TLS; a plain `ws://` URL would send the ticket in the clear. */
    private const string SCHEME = 'wss';

    /** The port a `wss://` URL means when it does not say one. */
    private const int DEFAULT_PORT = 443;

    /** @param string $path the path an upgrade is requested on, query string included */
    public function __construct(
        public string $host,
        public int $port,
        public string $path,
    ) {}

    /**
     * Splits the URL Slack handed out.
     *
     * The query is kept on the path: it carries the single-use ticket, and an upgrade without it is
     * refused.
     *
     * @throws SocketModeException when the URL is not a WSS URL naming a host
     */
    public static function fromUrl(string $url): self
    {
        $parts = parse_url($url);

        if ($parts === false) {
            throw new SocketModeException('Slack handed out a URL that cannot be parsed.');
        }

        if (($parts['scheme'] ?? null) !== self::SCHEME) {
            throw new SocketModeException('Slack handed out a URL that is not ' . self::SCHEME . '://.');
        }

        $host = $parts['host'] ?? null;

        if (!is_string($host)) {
            throw new SocketModeException('Slack handed out a URL without a host.');
        }

        $port = $parts['port'] ?? null;
        $path = is_string($parts['path'] ?? null) ? $parts['path'] : '/';
        $query = is_string($parts['query'] ?? null) ? "?{$parts['query']}" : '';

        return new self($host, is_int($port) ? $port : self::DEFAULT_PORT, "{$path}{$query}");
    }
}
