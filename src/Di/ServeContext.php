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
 * The context of the resident server: an ahead-of-time compiled injector, warmed up at boot.
 *
 * @api
 */
final class ServeContext extends AbstractContext implements CompiledContextInterface
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
    public function __invoke(): AbstractModule
    {
        // BeModule takes the application's own module as its parent, so the two are one module by
        // the time the compiler sees it. The namespace argument is what keeps Be looking for
        // semantic variables in this application rather than in its own default namespace.
        return new BeModule(AgentBridge::SEMANTIC_NAMESPACE, new AppModule());
    }
}
