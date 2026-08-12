<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Cli;

use NaokiTsuchiya\AgentBridge\Tests\Di\CompiledServe;
use NaokiTsuchiya\RayDiContext\Exception\InvalidAppMeta;
use Override;

/** The round trip as the application ships it: the resident execution layer. */
final class CliRoundTripTest extends CliRoundTripTestCase
{
    /**
     * {@inheritDoc}
     *
     * @throws InvalidAppMeta
     */
    #[Override]
    protected function appDir(): string
    {
        return CompiledServe::meta()->appDir;
    }
}
