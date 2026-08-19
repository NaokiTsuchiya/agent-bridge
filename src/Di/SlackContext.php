<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Di;

use Be\Framework\Module\BeModule;
use NaokiTsuchiya\AgentBridge\AgentBridge;
use NaokiTsuchiya\RayDiContext\AbstractContext;
use NaokiTsuchiya\RayDiContext\CompiledContextInterface;
use Override;
use Ray\Di\AbstractModule;

/**
 * The context of the Slack front end: the same application, answered into a workspace.
 *
 * A context of its own rather than a switch inside {@see ServeContext}, because which front end a
 * process has is decided once, when it starts, and a process that could be either would have to
 * build both — including a Web API client for a workspace nobody configured.
 *
 * Ahead-of-time compiled singletons in {@see SlackModule} (including Scope::SINGLETON providers such as
 * {@see \NaokiTsuchiya\AgentBridge\Slack\SlackApiClientProvider} and {@see \NaokiTsuchiya\AgentBridge\Slack\EnvelopeChannelProvider})
 * are pre-instantiated during warmup without performing network I/O or opening sockets on startup:
 * actual Slack API socket connections are opened exclusively by {@see \NaokiTsuchiya\AgentBridge\Slack\SocketModeConnectorInterface}
 * (via {@see \NaokiTsuchiya\AgentBridge\Slack\SocketModeConnectorProvider}), which is bound without singleton scope
 * and therefore excluded from warmup. Consequently, warming up all singletons on boot does not trigger connection attempts.
 *
 * @api
 */
final class SlackContext extends AbstractContext implements CompiledContextInterface
{
    /**
     * The context name, which is also one path segment of the compile and tmp directories.
     *
     * The compile command in composer.json passes this same string; a test holds the two together,
     * because a mismatch would have the process look for compiled scripts where nothing was written
     * and only show up when it is started.
     */
    public const string NAME = 'slack';

    /** {@inheritDoc} */
    #[Override]
    public function __invoke(): AbstractModule
    {
        return new BeModule(AgentBridge::SEMANTIC_NAMESPACE, new SlackModule());
    }
}
