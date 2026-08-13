<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Override;
use Ray\Di\ProviderInterface;

/**
 * Where the Socket Mode connector comes from, app token and all.
 *
 * A provider for the same reason {@see SlackApiClientProvider} is one: the token belongs to the
 * machine the process runs on, and a compiled script must not carry it.
 *
 * @implements ProviderInterface<SocketModeConnectorInterface>
 *
 * @api
 */
final class SocketModeConnectorProvider implements ProviderInterface
{
    /**
     * @param HttpClientFactoryInterface $clients  where the coroutine HTTP clients come from
     * @param SlackApiEndpoint           $endpoint where the handshake is opened against
     */
    public function __construct(
        private HttpClientFactoryInterface $clients,
        private SlackApiEndpoint $endpoint,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws SocketModeException when the app token is unset or unusable
     */
    #[Override]
    public function get(): SocketModeConnectorInterface
    {
        return new SwooleSocketModeConnector(
            SlackAppTokenFactory::fromEnvironment(),
            $this->clients,
            $this->endpoint->host,
            $this->endpoint->port,
        );
    }
}
