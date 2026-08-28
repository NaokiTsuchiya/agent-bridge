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
 * The scalar values a `Qualifier` attribute stands in for, resolved the way the compiled injector
 * resolves everything else — never read off the attribute's own reflection, which proves nothing
 * about whether Ray.Di can actually build one. The last two cases show {@see SpawnCliRunner}
 * resolving the same `TurnSeconds` attribute and the same assembled {@see ProcessRelease}
 * {@see PersistentCliRunner} does.
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
     * The deployment's grace for a terminated child, which is a wiring decision and not a constant.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     */
    #[Test]
    public function terminationGraceSecondsIsResolvedFromTheInjectorAsTwoSeconds(): void
    {
        $meta = CompiledServe::meta();
        $injector = (new InjectorBuilder())(new ServeContext($meta), $meta);

        self::assertSame(2.0, $injector->getInstance('', TerminationGraceSeconds::class));
    }

    /**
     * Both graces reach the part that spends them, and each reaches the right one of the two.
     *
     * Read off the built instance rather than the settings: the two are floats of the same type
     * asked for by attribute, so swapping the attributes compiles, resolves, and would leave a
     * terminated child polled for ten seconds and a closing one for two.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     * @throws ReflectionException
     */
    #[Test]
    public function theReleaseTheInjectorBuildsHoldsBothGracesTheRightWayRound(): void
    {
        $meta = CompiledServe::meta();
        $injector = (new InjectorBuilder())(new ServeContext($meta), $meta);

        $release = $injector->getInstance(ProcessRelease::class);

        self::assertSame(
            $injector->getInstance('', CloseGraceSeconds::class),
            new ReflectionProperty(ProcessRelease::class, 'closeGraceSeconds')->getValue($release),
        );
        self::assertSame(
            2.0,
            new ReflectionProperty(ProcessRelease::class, 'terminationGraceSeconds')->getValue($release),
        );
    }

    /**
     * The spawn runner is handed the same assembled release, rather than one of its own making.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     * @throws ReflectionException
     */
    #[Test]
    public function spawnRunnerTakesTheReleaseFromTheSameWiring(): void
    {
        $meta = CompiledServe::spawnMeta();
        $injector = (new InjectorBuilder())(new SpawnServeContext($meta), $meta);

        $runner = $injector->getInstance(AgentRunner::class);
        self::assertInstanceOf(SpawnCliRunner::class, $runner);

        /** @var ProcessRelease $release */
        $release = new ReflectionProperty(SpawnCliRunner::class, 'release')->getValue($runner);
        self::assertInstanceOf(ProcessRelease::class, $release);
        self::assertSame(
            2.0,
            new ReflectionProperty(ProcessRelease::class, 'terminationGraceSeconds')->getValue($release),
        );
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
