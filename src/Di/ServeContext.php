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
 * The context of the resident server: an ahead-of-time compiled injector, warmed up at boot.
 *
 * @api
 */
final class ServeContext extends AbstractCompiledContext
{
    /**
     * The context name, which is also one path segment of the compile and tmp directories.
     *
     * The compile command in composer.json passes this same string; a test holds the two together,
     * because a mismatch would have the server look for compiled scripts where nothing was written
     * and only show up when it is started.
     */
    public const string NAME = 'serve';

    /** {@inheritDoc} */
    #[Override]
    protected function appModule(): AbstractModule
    {
        // BeModule takes the application's own module as its parent, so the two are one module by
        // the time the compiler sees it. The namespace argument is what keeps Be looking for
        // semantic variables in this application rather than in its own default namespace.
        return new BeModule(AgentBridge::SEMANTIC_NAMESPACE, new AppModule());
    }

    /**
     * {@inheritDoc}
     *
     * The execution layer is here because it is the singleton that holds the child processes: left
     * to the first turn, its cost would land on whoever asked first. Slack's client is deliberately
     * absent — it is #14's, and a connection opened here would be one nobody had asked for.
     */
    #[Override]
    public function getSavedSingleton(): array
    {
        return [ResourceInterface::class, BecomingInterface::class, AgentRunner::class];
    }
}
