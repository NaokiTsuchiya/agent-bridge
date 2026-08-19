<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Di;

use Be\Framework\Module\BeModule;
use NaokiTsuchiya\AgentBridge\AgentBridge;
use NaokiTsuchiya\AgentBridge\Di\AppModule;
use NaokiTsuchiya\AgentBridge\Di\ServeContext;
use NaokiTsuchiya\RayDiContext\AbstractContext;
use NaokiTsuchiya\RayDiContext\CompiledContextInterface;
use Override;
use Ray\Di\AbstractModule;

/**
 * The serve context with the execution layer swapped, compiled into an app dir of its own.
 *
 * Only the compile ever sees this class: what a process resolves from is
 * `CompiledInjector($meta->compileDir)`, so pointing a run at the app dir this context was compiled
 * into is the entire substitution — `bin/agent-bridge-cli` and the production {@see ServeContext}
 * are used unchanged, and neither knows which runner it got.
 *
 * It repeats {@see ServeContext}'s contract rather than extending it, because that one is final,
 * and deliberately so: a deployment ships one context.
 */
final class SpawnServeContext extends AbstractContext implements CompiledContextInterface
{
    /** {@inheritDoc} */
    #[Override]
    public function __invoke(): AbstractModule
    {
        return new BeModule(AgentBridge::SEMANTIC_NAMESPACE, new SpawnRunnerModule(new AppModule()));
    }
}
