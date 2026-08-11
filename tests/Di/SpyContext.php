<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Di;

use NaokiTsuchiya\RayDiContext\AppMeta;
use NaokiTsuchiya\RayDiContext\ContextInterface;
use NaokiTsuchiya\RayDiContext\ContextProviderInterface;
use Override;
use Ray\Di\AbstractModule;
use Ray\Di\InjectorInterface;

/**
 * A context that counts how often its injector is asked for.
 *
 * The real contract allows a context to hand back a different injector each call — the compiled one
 * does — so "how many times was it asked" is the only thing that says whether a caller kept the
 * one it was given. It is a provider as well as a context so that one object can be handed to
 * {@see \NaokiTsuchiya\AgentBridge\Di\Boot} and read afterwards.
 */
final class SpyContext implements ContextInterface, ContextProviderInterface
{
    /** How many times getInjectorInstance() has been called. */
    public int $injectorCalls = 0;

    /** The injector handed out, which records what the warmup asked it to build. */
    public RecordingInjector $injector;

    /** @param list<class-string> $savedSingleton what the caller should warm up */
    public function __construct(
        private array $savedSingleton = [],
    ) {
        $this->injector = new RecordingInjector();
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
        $this->injectorCalls++;

        return $this->injector;
    }

    /** {@inheritDoc} */
    #[Override]
    public function getSavedSingleton(): array
    {
        return $this->savedSingleton;
    }
}
