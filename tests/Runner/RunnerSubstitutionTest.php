<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use NaokiTsuchiya\AgentBridge\Chat\ChatEgress;
use NaokiTsuchiya\AgentBridge\Di\CompiledServe;
use NaokiTsuchiya\AgentBridge\Di\ServeContext;
use NaokiTsuchiya\AgentBridge\Di\SpawnRunnerModule;
use NaokiTsuchiya\RayDiContext\Exception\CompileDirUnavailable;
use NaokiTsuchiya\RayDiContext\Exception\InvalidAppMeta;
use NaokiTsuchiya\RayDiContext\InjectorBuilder;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use Swoole\Runtime;

use function file_get_contents;
use function str_contains;
use function substr_count;

/**
 * That the execution layer is a place two things can stand, shown rather than asserted in prose.
 *
 * The PoC's fourth goal is the abstraction holding up under a second implementation, and the way to
 * see that it does is what a swap costs: one module naming the other runner, no change to the
 * interface, the ports or the front end, and a deployment that still ships the resident one.
 */
final class RunnerSubstitutionTest extends TestCase
{
    /** Swoole's hook flags as they were before this class ran. */
    private static int $hookFlags = 0;

    /** {@inheritDoc} */
    #[Override]
    public static function setUpBeforeClass(): void
    {
        self::$hookFlags = Runtime::getHookFlags();
    }

    /** Building either runner turns Swoole's hooks on process-wide; they go back off here. */
    #[Override]
    public static function tearDownAfterClass(): void
    {
        Runtime::setHookFlags(self::$hookFlags);
    }

    /**
     * The second implementation is one, by the only definition that matters.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function theSpawnRunnerIsAnAgentRunner(): void
    {
        self::assertTrue(new ReflectionClass(SpawnCliRunner::class)->implementsInterface(AgentRunner::class));
    }

    /**
     * It is a second implementation and not a variation of the first: nothing is inherited.
     *
     * A subclass would prove nothing about the interface — it would prove that one runner can be
     * bent into another, which is a statement about {@see PersistentCliRunner}, not about
     * {@see AgentRunner}.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function theSpawnRunnerInheritsNothing(): void
    {
        self::assertFalse(new ReflectionClass(SpawnCliRunner::class)->getParentClass());
    }

    /**
     * A run pointed at the swapped compile answers with the other runner.
     *
     * The context is the production one and so is the app dir's shape: what differs is which module
     * was compiled into it, which is the whole of the substitution.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     */
    #[Test]
    public function theSwappedWiringResolvesTheSpawnRunner(): void
    {
        $meta = CompiledServe::spawnMeta();
        $injector = (new InjectorBuilder())(new ServeContext($meta), $meta);

        self::assertInstanceOf(SpawnCliRunner::class, $injector->getInstance(AgentRunner::class));
    }

    /**
     * What the application ships is still the resident runner.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     */
    #[Test]
    public function theDeploymentStillGetsTheResidentRunner(): void
    {
        $meta = CompiledServe::meta();
        $injector = (new InjectorBuilder())(new ServeContext($meta), $meta);

        self::assertInstanceOf(PersistentCliRunner::class, $injector->getInstance(AgentRunner::class));
    }

    /**
     * The swap declares the execution layer and nothing else.
     *
     * Read off the source because that is the claim: one implementation named, and none of the
     * parts a runner is built from — a module that had to re-declare the resolver, the settings or
     * the front end would mean the execution layer is not the seam it is meant to be. The turn
     * allowance is the one thing it declares besides the runner, because Ray.Di cannot be asked for
     * a `float` by type.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function theSwapDeclaresNothingButTheExecutionLayer(): void
    {
        $file = new ReflectionClass(SpawnRunnerModule::class)->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);

        self::assertSame(1, substr_count($source, needle: 'AgentRunner::class'), 'One runner is named.');
        foreach ([WorkingDirectoryResolver::class, ClaudeCliSettings::class, ChatEgress::class] as $part) {
            $short = new ReflectionClass($part)->getShortName();
            self::assertFalse(str_contains($source, $short), "The swap re-declares {$short}.");
        }
    }
}
