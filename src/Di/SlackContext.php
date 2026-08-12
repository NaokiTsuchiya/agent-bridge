<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Di;

use Be\Framework\BecomingInterface;
use Be\Framework\Module\BeModule;
use BEAR\Resource\ResourceInterface;
use NaokiTsuchiya\AgentBridge\AgentBridge;
use NaokiTsuchiya\AgentBridge\Runner\AgentRunner;
use NaokiTsuchiya\RayDiContext\AbstractCompiledContext;
use Override;
use Ray\Di\AbstractModule;

/**
 * The context of the Slack front end: the same application, answered into a workspace.
 *
 * A context of its own rather than a switch inside {@see ServeContext}, because which front end a
 * process has is decided once, when it starts, and a process that could be either would have to
 * build both — including a Web API client for a workspace nobody configured.
 *
 * @api
 */
final class SlackContext extends AbstractCompiledContext
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
    protected function appModule(): AbstractModule
    {
        return new BeModule(AgentBridge::SEMANTIC_NAMESPACE, new SlackModule());
    }

    /**
     * {@inheritDoc}
     *
     * The same three as {@see ServeContext}, and deliberately no more: the Slack side of this
     * context reads tokens as it is built, and a warmup that touched it would turn every start into
     * a connection attempt before the process had said it was up.
     */
    #[Override]
    public function getSavedSingleton(): array
    {
        return [ResourceInterface::class, BecomingInterface::class, AgentRunner::class];
    }
}
