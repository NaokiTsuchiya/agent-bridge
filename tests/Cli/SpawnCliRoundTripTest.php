<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Cli;

use NaokiTsuchiya\AgentBridge\Di\CompiledServe;
use NaokiTsuchiya\RayDiContext\Exception\InvalidAppMeta;
use Override;

/**
 * The same round trip on the other execution layer, expecting the same of it.
 *
 * Nothing below the front end is mentioned here and nothing above it changed: this file is the
 * evidence that the execution layer is replaceable, and the cases it inherits are the standard it
 * has to meet.
 */
final class SpawnCliRoundTripTest extends CliRoundTripTestCase
{
    /**
     * {@inheritDoc}
     *
     * @throws InvalidAppMeta
     */
    #[Override]
    protected function appDir(): string
    {
        return CompiledServe::spawnMeta()->appDir;
    }
}
