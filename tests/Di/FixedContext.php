<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Di;

use NaokiTsuchiya\RayDiContext\AppMeta;
use NaokiTsuchiya\RayDiContext\ContextInterface;
use NaokiTsuchiya\RayDiContext\ContextProviderInterface;
use Override;
use Ray\Di\AbstractModule;
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
    /** @param array<class-string, object|Throwable> $prepared what the module binds */
    public function __construct(
        private readonly array $prepared = [],
    ) {}

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
        return new FixedModule($this->prepared);
    }
}
