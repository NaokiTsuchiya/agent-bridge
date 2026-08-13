<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Di;

use NaokiTsuchiya\AgentBridge\Chat\ChatEgress;
use NaokiTsuchiya\AgentBridge\Slack\Backoff;
use NaokiTsuchiya\AgentBridge\Slack\ClockInterface;
use NaokiTsuchiya\AgentBridge\Slack\CoroutineSleeper;
use NaokiTsuchiya\AgentBridge\Slack\EnvelopeChannelProvider;
use NaokiTsuchiya\AgentBridge\Slack\EnvelopeLog;
use NaokiTsuchiya\AgentBridge\Slack\FrameRouter;
use NaokiTsuchiya\AgentBridge\Slack\HttpClientFactoryInterface;
use NaokiTsuchiya\AgentBridge\Slack\MtRandomSource;
use NaokiTsuchiya\AgentBridge\Slack\RandomSourceInterface;
use NaokiTsuchiya\AgentBridge\Slack\ReconnectDelay;
use NaokiTsuchiya\AgentBridge\Slack\SlackApiClient;
use NaokiTsuchiya\AgentBridge\Slack\SlackApiClientProvider;
use NaokiTsuchiya\AgentBridge\Slack\SlackApiEndpoint;
use NaokiTsuchiya\AgentBridge\Slack\SlackApiEndpointProvider;
use NaokiTsuchiya\AgentBridge\Slack\SlackEgress;
use NaokiTsuchiya\AgentBridge\Slack\SlackIdentity;
use NaokiTsuchiya\AgentBridge\Slack\SlackIdentityProvider;
use NaokiTsuchiya\AgentBridge\Slack\SlackIngress;
use NaokiTsuchiya\AgentBridge\Slack\SlackLoggerInterface;
use NaokiTsuchiya\AgentBridge\Slack\SlackServer;
use NaokiTsuchiya\AgentBridge\Slack\SleeperInterface;
use NaokiTsuchiya\AgentBridge\Slack\SocketModeClient;
use NaokiTsuchiya\AgentBridge\Slack\SocketModeConnectorInterface;
use NaokiTsuchiya\AgentBridge\Slack\SocketModeConnectorProvider;
use NaokiTsuchiya\AgentBridge\Slack\StderrSlackLogger;
use NaokiTsuchiya\AgentBridge\Slack\StreamingSettings;
use NaokiTsuchiya\AgentBridge\Slack\SwooleHttpClientFactory;
use NaokiTsuchiya\AgentBridge\Slack\SystemClock;
use NaokiTsuchiya\AgentBridge\Slack\ThreadChannels;
use Override;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;
use Swoole\Coroutine\Channel;

/**
 * The application with a Slack workspace in front of it instead of a terminal.
 *
 * Installing {@see AppModule} and then binding {@see ChatEgress} again is the whole of the swap:
 * a later binding wins, and everything from the pipeline down is the one the command line uses.
 * That the file is this short is the point of the ports — see `docs/slack-adapter.md`.
 *
 * @api
 */
final class SlackModule extends AbstractModule
{
    /** {@inheritDoc} */
    #[Override]
    protected function configure(): void
    {
        $this->install(new AppModule());

        // The one binding of the application's own that is answered differently here. Singleton
        // because the map of thread to channel below is only useful if everyone shares it.
        $this->bind(ChatEgress::class)->to(SlackEgress::class)->in(Scope::SINGLETON);
        // Written by the ingress, read by the egress; one map or no answers.
        $this->bind(ThreadChannels::class)->in(Scope::SINGLETON);
        // The queue the connection fills and the ingress drains. Singleton for the same reason.
        $this->bind(Channel::class)->toProvider(EnvelopeChannelProvider::class)->in(Scope::SINGLETON);

        // Everything that reads a credential is asked for it at the moment it is built, never
        // compiled into a script.
        $this->bind(SlackApiClient::class)->toProvider(SlackApiClientProvider::class)->in(Scope::SINGLETON);
        $this->bind(SlackIdentity::class)->toProvider(SlackIdentityProvider::class)->in(Scope::SINGLETON);
        $this->bind(SocketModeConnectorInterface::class)->toProvider(SocketModeConnectorProvider::class);
        // Not a credential, but read at the same moment and for the same reason: where the API is
        // reached is the running machine's business, and a compiled script cannot hold it either.
        $this->bind(SlackApiEndpoint::class)->toProvider(SlackApiEndpointProvider::class)->in(Scope::SINGLETON);

        $this->bind(HttpClientFactoryInterface::class)->to(SwooleHttpClientFactory::class);
        $this->bind(SlackLoggerInterface::class)->to(StderrSlackLogger::class);
        $this->bind(RandomSourceInterface::class)->to(MtRandomSource::class);
        $this->bind(SleeperInterface::class)->to(CoroutineSleeper::class);
        // How fast a reply may be sent, and what tells it how much time has gone by. Both are here
        // rather than inside the front end so that a deployment can move the pace without a change.
        $this->bind(StreamingSettings::class);
        $this->bind(ClockInterface::class)->to(SystemClock::class);
        // Bound although nothing in this file names them as a parameter: a compiled injector
        // answers only for what was bound when it was compiled, and these are what the server is
        // built out of.
        $this->bind(SlackIngress::class);
        $this->bind(SocketModeClient::class);
        $this->bind(FrameRouter::class);
        $this->bind(EnvelopeLog::class);
        $this->bind(ReconnectDelay::class);
        $this->bind(Backoff::class);
        // What the front end process resolves, in place of the parts it would otherwise assemble.
        $this->bind(SlackServer::class);
    }
}
