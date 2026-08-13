<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use InvalidArgumentException;

/**
 * Where the Slack API is reached: the host and port both the Web API calls and the Socket Mode
 * handshake are aimed at.
 *
 * It knows nothing about where an endpoint is configured ({@see SlackApiEndpointProvider}) or what
 * is opened against it ({@see SwooleSlackApiClient}, {@see SwooleSocketModeConnector}) — only what
 * makes a pair one a client can be pointed at.
 *
 * @api
 */
final readonly class SlackApiEndpoint
{
    /** Port 0 asks the OS to pick one, which is meaningless as somewhere to connect to. */
    public const int LOWEST_PORT = 1;

    /** The widest a TCP port number goes. */
    public const int HIGHEST_PORT = 65_535;

    /**
     * @param string $host where the API is asked for
     * @param int    $port the port that host is reached on
     *
     * @throws InvalidArgumentException when the port is not one anything can be connected to
     */
    public function __construct(
        public string $host,
        public int $port,
    ) {
        if ($port < self::LOWEST_PORT || $port > self::HIGHEST_PORT) {
            throw new InvalidArgumentException(
                'A port is between ' . self::LOWEST_PORT . ' and ' . self::HIGHEST_PORT . ", got {$port}.",
            );
        }
    }
}
