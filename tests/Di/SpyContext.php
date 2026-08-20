<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Di;

use NaokiTsuchiya\RayDiContext\AppMeta;
use NaokiTsuchiya\RayDiContext\ContextInterface;
use NaokiTsuchiya\RayDiContext\ContextProviderInterface;
use Override;
use Ray\Di\AbstractModule;

/**
 * A context that counts how often it is requested from the provider.
 *
 * It is a provider as well as a context so that one object can be handed to
 * {@see \NaokiTsuchiya\AgentBridge\Di\Boot} and read afterwards.
 */
final class SpyContext implements ContextInterface, ContextProviderInterface
{
    /** How many times get() has been called. */
    public int $contextCalls = 0;

    /** {@inheritDoc} */
    #[Override]
    public function get(AppMeta $meta): ContextInterface
    {
        $this->contextCalls++;

        return $this;
    }

    /** {@inheritDoc} */
    #[Override]
    public function __invoke(): AbstractModule
    {
        return new EmptyModule();
    }
}
