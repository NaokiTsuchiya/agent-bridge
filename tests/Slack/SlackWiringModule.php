<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Di\SlackModule;
use Override;
use Ray\Di\AbstractModule;

/**
 * The Slack deployment with its three credentials taken out.
 *
 * Everything else is the real {@see SlackModule}, installed and then bound over — which is the only
 * way to ask what a Slack process is actually wired to without a workspace, a token and a socket.
 * A binding that went missing from the module would go missing here too.
 *
 * @internal
 */
final class SlackWiringModule extends AbstractModule
{
    /**
     * {@inheritDoc}
     *
     * @throws InvalidArgumentException never; the identity below is a usable one
     */
    #[Override]
    protected function configure(): void
    {
        $this->install(new SlackModule());

        // The three things a real process reads from the environment, and nothing besides.
        $this->bind(SlackApiClient::class)->toInstance(new FakeSlackApiClient());
        $this->bind(SlackIdentity::class)->toInstance(new SlackIdentity('U0BOT'));
        $this->bind(SocketModeConnectorInterface::class)->toInstance(new FakeSocketModeConnector([]));
    }
}
