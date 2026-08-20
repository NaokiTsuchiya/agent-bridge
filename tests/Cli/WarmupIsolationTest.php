<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Cli;

use NaokiTsuchiya\AgentBridge\Di\CompiledServe;
use NaokiTsuchiya\RayDiContext\AppMeta;
use NaokiTsuchiya\RayDiContext\Exception\InvalidAppMeta;
use Override;

/** Two threads through the warmed-up singleton the application ships. */
final class WarmupIsolationTest extends WarmupIsolationTestCase
{
    /**
     * {@inheritDoc}
     *
     * @throws InvalidAppMeta
     */
    #[Override]
    protected function meta(): AppMeta
    {
        return CompiledServe::meta();
    }
}
