<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Di;

use NaokiTsuchiya\AgentBridge\Runner\AgentRunner;
use NaokiTsuchiya\AgentBridge\Runner\SpawnCliRunner;
use Override;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

/**
 * The whole of what it takes to run this application on the other execution layer.
 *
 * One execution layer named, and nothing else: everything it is built from, including the turn
 * timeout, is bound where every runner's parts are bound ({@see \NaokiTsuchiya\AgentBridge\Di\AppModule}),
 * which is the point: choosing the other implementation costs a declaration, not a wiring.
 *
 * It is written as a module that **wraps** the application's own rather than one that installs it,
 * because installing merges without overwriting (`Ray\Di\Container::merge()` adds with `+=`), while
 * a wrapped module is merged after this one's `configure()` has run and therefore loses to it —
 * which is what makes the binding below a replacement rather than a duplicate.
 */
final class SpawnRunnerModule extends AbstractModule
{
    /** {@inheritDoc} */
    #[Override]
    protected function configure(): void
    {
        $this->bind(AgentRunner::class)->to(SpawnCliRunner::class)->in(Scope::SINGLETON);
    }
}
