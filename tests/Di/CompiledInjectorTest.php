<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Di;

use Be\Framework\Becoming;
use Be\Framework\BecomingInterface;
use BEAR\Resource\Exception\BadRequestException;
use BEAR\Resource\ResourceInterface;
use NaokiTsuchiya\AgentBridge\AgentBridge;
use NaokiTsuchiya\AgentBridge\Di\ServeContext;
use NaokiTsuchiya\AgentBridge\Runner\AgentRunner;
use NaokiTsuchiya\AgentBridge\Runner\LifecycleSettings;
use NaokiTsuchiya\AgentBridge\Runner\PersistentCliRunner;
use NaokiTsuchiya\AgentBridge\Worktree\WorktreeManager;
use NaokiTsuchiya\RayDiContext\Exception\CompileDirUnavailable;
use NaokiTsuchiya\RayDiContext\Exception\InvalidAppMeta;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ray\Di\InjectorInterface;
use Swoole\Runtime;

/**
 * What the compiled injector can build, which is the question the PoC's goal 5 asks.
 *
 * A compiled injector only knows what was bound when it was compiled: an interface nobody bound is
 * not resolved on the fly the way a plain `Injector` would resolve it. So every case here reads the
 * scripts a real compile wrote ({@see CompiledServe}), never a live module.
 *
 * @mago-expect lint:too-many-methods
 */
final class CompiledInjectorTest extends TestCase
{
    /** The one injector this test process resolves from, as a served process would keep one. */
    private static ?InjectorInterface $injector = null;

    /** Swoole's hook flags as they were before this class ran. */
    private static int $hookFlags = 0;

    /**
     * Building the execution layer turns on Swoole's `proc_open` hook process-wide, which is what
     * lets a turn wait without freezing the other threads. It is not this class's to leave on: a
     * later case that starts a process outside a coroutine — the fake CLI's cases do — dies with
     * "API must be called in the coroutine" once it is, and which cases those are depends on the
     * order the suite happens to run in.
     */
    #[Override]
    public static function setUpBeforeClass(): void
    {
        self::$hookFlags = Runtime::getHookFlags();
    }

    /** Puts the hook flags back and drops the injector, which holds the execution layer. */
    #[Override]
    public static function tearDownAfterClass(): void
    {
        Runtime::setHookFlags(self::$hookFlags);
        self::$injector = null;
    }

    /**
     * The execution layer, which is what the resident process exists to run.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     */
    #[Test]
    public function resolvesTheExecutionLayer(): void
    {
        $runner = self::injector()->getInstance(AgentRunner::class);

        self::assertInstanceOf(PersistentCliRunner::class, $runner);
    }

    /**
     * The repository path reaches the manager through a provider, so this shows the provider ran —
     * a value frozen at compile time would have failed the compile rather than this resolution.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     */
    #[Test]
    public function resolvesWorktreeManagement(): void
    {
        $worktrees = self::injector()->getInstance(WorktreeManager::class);

        self::assertInstanceOf(WorktreeManager::class, $worktrees);
    }

    /**
     * The lifecycle rules are settings rather than a service, and the pool is built from them.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     */
    #[Test]
    public function resolvesProcessLifecycleManagement(): void
    {
        $limits = self::injector()->getInstance(LifecycleSettings::class);

        self::assertInstanceOf(LifecycleSettings::class, $limits);
    }

    /**
     * Goal 5's first half: the resource layer is wired without bear/skeleton underneath it.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     */
    #[Test]
    public function resolvesTheResourceClient(): void
    {
        $resource = self::injector()->getInstance(ResourceInterface::class);

        self::assertInstanceOf(ResourceInterface::class, $resource);
    }

    /**
     * The risk this case exists for: `BecomingArguments` works by reflection, and whether that
     * survives ahead-of-time compiled bindings is what #10's design rests on.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     */
    #[Test]
    public function resolvesBecoming(): void
    {
        $becoming = self::injector()->getInstance(BecomingInterface::class);

        self::assertInstanceOf(Becoming::class, $becoming);
    }

    /**
     * Resolving the client proves nothing on its own — one comes back whether or not a single
     * resource class exists. Asking over a URI is what needs the class to have been bound.
     *
     * @throws BadRequestException
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     */
    #[Test]
    public function answersOverAUri(): void
    {
        $resource = self::injector()->getInstance(ResourceInterface::class);

        $health = $resource->uri('app://self/health')();

        self::assertSame(['status' => 'ok', 'package' => AgentBridge::PACKAGE], $health->body);
    }

    /**
     * A second runner would hold children of its own and neither would honour the other's limit.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     */
    #[Test]
    public function keepsOneExecutionLayerPerInjector(): void
    {
        $injector = self::injector();

        self::assertSame($injector->getInstance(AgentRunner::class), $injector->getInstance(AgentRunner::class));
    }

    /**
     * BEAR binds the client as a singleton, and nothing here overrides that.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     */
    #[Test]
    public function keepsOneResourceClientPerInjector(): void
    {
        $injector = self::injector();

        self::assertSame(
            $injector->getInstance(ResourceInterface::class),
            $injector->getInstance(ResourceInterface::class),
        );
    }

    /**
     * Be binds `Becoming` without a scope. Asserted so that the two cases above are read as a
     * property of those bindings rather than of the injector, which would return the same instance
     * for everything if it cached.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     */
    #[Test]
    public function buildsAnUnscopedBindingEveryTime(): void
    {
        $injector = self::injector();

        self::assertNotSame(
            $injector->getInstance(BecomingInterface::class),
            $injector->getInstance(BecomingInterface::class),
        );
    }

    /**
     * The injector of the compiled scripts, built once the way a process is meant to build it.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     */
    private static function injector(): InjectorInterface
    {
        if (self::$injector === null) {
            self::$injector = new ServeContext(CompiledServe::meta())->getInjectorInstance();
        }

        return self::$injector;
    }
}
