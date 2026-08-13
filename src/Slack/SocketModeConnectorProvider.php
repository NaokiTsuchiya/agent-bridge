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
    /** @param HttpClientFactoryInterface $clients where the coroutine HTTP clients come from */
    public function __construct(
        private HttpClientFactoryInterface $clients,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws SocketModeException when the app token is unset or unusable
     * @throws SlackException      when the endpoint variables do not name a host and port
     */
    #[Override]
    public function get(): SocketModeConnectorInterface
    {
        // Read before the token, so that a wrong port is said to be wrong even on a machine that
        // has no app token yet: both are settings of the same start, and the first one asked about
        // is the one a person is told to fix.
        $endpoint = SlackApiEndpoint::fromEnvironment();

        return new SwooleSocketModeConnector(
            SlackAppTokenFactory::fromEnvironment(),
            $this->clients,
            $endpoint->host,
            $endpoint->port,
        );
    }
}
