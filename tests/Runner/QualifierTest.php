<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use NaokiTsuchiya\AgentBridge\Di\CompiledServe;
use NaokiTsuchiya\AgentBridge\Di\ServeContext;
use NaokiTsuchiya\AgentBridge\Di\SpawnServeContext;
use NaokiTsuchiya\RayDiContext\Exception\CompileDirUnavailable;
use NaokiTsuchiya\RayDiContext\Exception\InvalidAppMeta;
use NaokiTsuchiya\RayDiContext\InjectorBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionProperty;

/**
 * The two scalar values a `Qualifier` attribute stands in for, resolved the way the compiled
 * injector resolves everything else — never read off the attribute's own reflection, which proves
 * nothing about whether Ray.Di can actually build one. The third case shows {@see SpawnCliRunner}
 * resolving the same `TurnSeconds` attribute {@see PersistentCliRunner} does.
 */
final class QualifierTest extends TestCase
{
    /**
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     */
    #[Test]
    public function turnSecondsIsResolvedFromTheInjector(): void
    {
        $meta = CompiledServe::meta();
        $injector = (new InjectorBuilder())(new ServeContext($meta), $meta);

        self::assertIsFloat($injector->getInstance('', TurnSeconds::class));
    }

    /**
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     */
    #[Test]
    public function closeGraceSecondsIsResolvedFromTheInjector(): void
    {
        $meta = CompiledServe::meta();
        $injector = (new InjectorBuilder())(new ServeContext($meta), $meta);

        self::assertIsFloat($injector->getInstance('', CloseGraceSeconds::class));
    }

    /**
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     * @throws ReflectionException
     */
    #[Test]
    public function spawnRunnerTurnSecondsMatchesTheResidentRunners(): void
    {
        $meta = CompiledServe::spawnMeta();
        $injector = (new InjectorBuilder())(new SpawnServeContext($meta), $meta);

        $runner = $injector->getInstance(AgentRunner::class);
        self::assertInstanceOf(SpawnCliRunner::class, $runner);

        $turnSeconds = new ReflectionProperty(SpawnCliRunner::class, 'turnSeconds');
        self::assertSame($injector->getInstance('', TurnSeconds::class), $turnSeconds->getValue($runner));
    }
}
