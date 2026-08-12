<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Cli;

use NaokiTsuchiya\AgentBridge\Tests\Di\CompiledServe;
use NaokiTsuchiya\RayDiContext\AppMeta;
use NaokiTsuchiya\RayDiContext\Exception\InvalidAppMeta;
use Override;

/** The same two threads through a singleton that keeps no process between them. */
final class SpawnWarmupIsolationTest extends WarmupIsolationTestCase
{
    /**
     * {@inheritDoc}
     *
     * @throws InvalidAppMeta
     */
    #[Override]
    protected function meta(): AppMeta
    {
        return CompiledServe::spawnMeta();
    }
}
