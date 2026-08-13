<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Di;

use NaokiTsuchiya\RayDiContext\AppMeta;
use NaokiTsuchiya\RayDiContext\ContextInterface;
use NaokiTsuchiya\RayDiContext\ContextProviderInterface;
use Override;
use Ray\Di\AbstractModule;
use Ray\Di\InjectorInterface;
use Throwable;

/**
 * A context whose injector answers with what a case prepared, in place of compiled scripts.
 *
 * This is what lets a command be driven past the point where it starts: booting reads the mapping
 * it was given and nothing else, so a case that hands one of these over decides what the command
 * resolves without a `composer compile` behind it. It is a provider as well as a context for the
 * same reason {@see SpyContext} is — one object goes in and the boot sequence finds everything.
 *
 * @internal
 */
final class FixedContext implements ContextInterface, ContextProviderInterface
{
    /** The injector handed out, which answers with the prepared instances. */
    private FixedInjector $injector;

    /** @param array<class-string, object|Throwable> $prepared what the injector answers with */
    public function __construct(array $prepared = [])
    {
        $this->injector = new FixedInjector($prepared);
    }

    /** {@inheritDoc} */
    #[Override]
    public function get(AppMeta $meta): ContextInterface
    {
        return $this;
    }

    /** {@inheritDoc} */
    #[Override]
    public function __invoke(): AbstractModule
    {
        return new EmptyModule();
    }

    /** {@inheritDoc} */
    #[Override]
    public function getInjectorInstance(): InjectorInterface
    {
        return $this->injector;
    }

    /** {@inheritDoc} */
    #[Override]
    public function getSavedSingleton(): array
    {
        return [];
    }
}
