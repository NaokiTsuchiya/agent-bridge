<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Di;

use NaokiTsuchiya\RayDiContext\AppMeta;
use NaokiTsuchiya\RayDiContext\CompiledContextInterface;
use NaokiTsuchiya\RayDiContext\ContextInterface;
use NaokiTsuchiya\RayDiContext\ContextProviderInterface;
use Override;
use Ray\Di\AbstractModule;

/**
 * A compiled context spy that counts how often it is requested from the provider.
 *
 * @internal
 */
final class SpyCompiledContext implements CompiledContextInterface, ContextProviderInterface
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
