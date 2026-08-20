<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Cli;

use NaokiTsuchiya\AgentBridge\AgentBridge;
use NaokiTsuchiya\AgentBridge\Chat\ChatEgress;
use NaokiTsuchiya\AgentBridge\Di\CompiledServe;
use NaokiTsuchiya\AgentBridge\Di\ServeContext;
use NaokiTsuchiya\AgentBridge\Pipeline\AnsweringTurn;
use NaokiTsuchiya\AgentBridge\Pipeline\CompletedTurn;
use NaokiTsuchiya\AgentBridge\Pipeline\FailedTurn;
use NaokiTsuchiya\AgentBridge\Pipeline\ResolvedThread;
use NaokiTsuchiya\AgentBridge\Runner\PersistentCliRunner;
use NaokiTsuchiya\AgentBridge\Runner\ProcessRecipe;
use NaokiTsuchiya\AgentBridge\Runner\WorkingDirectoryResolver;
use NaokiTsuchiya\AgentBridge\Runner\WorktreeWorkingDirectory;
use NaokiTsuchiya\AgentBridge\Worktree\WorktreeManager;
use NaokiTsuchiya\RayDiContext\Exception\CompileDirUnavailable;
use NaokiTsuchiya\RayDiContext\Exception\InvalidAppMeta;
use NaokiTsuchiya\RayDiContext\InjectorBuilder;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ray\Di\InjectorInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use Swoole\Runtime;

use function class_exists;
use function dirname;
use function file_get_contents;
use function glob;
use function interface_exists;
use function is_subclass_of;
use function str_contains;
use function str_replace;
use function strlen;
use function substr;
use function substr_count;

use const GLOB_BRACE;

/**
 * What a started process is actually wired to, read out of the compiled scripts.
 *
 * Every case here is about a wire that has no other way of being seen. The chain reaches its
 * collaborators by type while a message is being answered, so one that nobody bound goes missing
 * in the middle of somebody's turn; and a runner sent to the wrong directory would still answer,
 * only in a directory that is not the thread's.
 *
 * @mago-expect lint:too-many-methods
 */
final class ProductionWiringTest extends TestCase
{
    /** The one injector this test process resolves from. */
    private static ?InjectorInterface $injector = null;

    /** Swoole's hook flags as they were before this class ran. */
    private static int $hookFlags = 0;

    /** {@inheritDoc} */
    #[Override]
    public static function setUpBeforeClass(): void
    {
        self::$hookFlags = Runtime::getHookFlags();
    }

    /** Building the execution layer turns Swoole's hooks on process-wide; they go back off here. */
    #[Override]
    public static function tearDownAfterClass(): void
    {
        Runtime::setHookFlags(self::$hookFlags);
        self::$injector = null;
    }

    /**
     * The front end the pipeline writes through, which nothing bound before this issue.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     */
    #[Test]
    public function resolvesTheFrontEndThePipelineWritesTo(): void
    {
        $egress = self::injector()->getInstance(ChatEgress::class);

        self::assertInstanceOf(StandardOutputEgress::class, $egress);
    }

    /**
     * One front end per process: it holds the process's own streams, and a second one would open
     * them again for every turn.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     */
    #[Test]
    public function keepsOneFrontEndPerInjector(): void
    {
        $injector = self::injector();

        self::assertSame($injector->getInstance(ChatEgress::class), $injector->getInstance(ChatEgress::class));
    }

    /**
     * The runner is sent to the thread's worktree by the manager that cuts it — not by a stand-in
     * that answers with one fixed directory, which is what #7 was tested against.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     * @throws ReflectionException
     */
    #[Test]
    public function sendsTheRunnerToTheWorktreeManager(): void
    {
        // Four links, each of which would let the runner work somewhere else if it were missing:
        // the runner asks for a recipe, the recipe asks for a resolver, the deployment answers
        // with the worktree one, and that one can only be built out of the manager.
        self::assertSame(ProcessRecipe::class, self::firstParameterOf(PersistentCliRunner::class));
        self::assertSame(WorkingDirectoryResolver::class, self::firstParameterOf(ProcessRecipe::class));
        self::assertInstanceOf(
            WorktreeWorkingDirectory::class,
            self::injector()->getInstance(WorkingDirectoryResolver::class),
        );
        self::assertSame(WorktreeManager::class, self::firstParameterOf(WorktreeWorkingDirectory::class));
    }

    /**
     * @param class-string $class
     *
     * @return string the type its constructor takes first, which is what a binding answers
     *
     * @throws ReflectionException
     */
    private static function firstParameterOf(string $class): string
    {
        $parameters = new ReflectionMethod($class, '__construct')->getParameters();
        $type = ($parameters[0] ?? null)?->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);

        return $type->getName();
    }

    /**
     * The other half of the same question: the stand-in cannot be what a deployment resolves,
     * because the application ships exactly one way of finding a thread's directory.
     */
    #[Test]
    public function shipsOneWayOfFindingAWorkingDirectory(): void
    {
        $implementations = [];
        foreach (self::sourceClasses() as $class) {
            if (!is_subclass_of($class, WorkingDirectoryResolver::class)) {
                continue;
            }

            $implementations[] = $class;
        }

        self::assertSame([WorktreeWorkingDirectory::class], $implementations);
    }

    /**
     * What answers the messages is one object the injector builds, not a set of parts the front
     * end put together itself.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     */
    #[Test]
    public function resolvesTheConversationAsOneThing(): void
    {
        self::assertInstanceOf(Conversation::class, self::injector()->getInstance(Conversation::class));
    }

    /**
     * The command asks the injector for exactly that one thing.
     *
     * Read off the source because it is a statement about the seam rather than about behaviour: a
     * command that resolved its collaborators one by one would answer just as well, while deciding
     * how a conversation is put together — which belongs to the module.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function asksTheInjectorForOneThing(): void
    {
        self::assertSame(1, substr_count(self::sourceOf(CliCommand::class), needle: 'getInstance('));
    }

    /**
     * The front end drives the chain by handing a message over, and nowhere builds a stage itself.
     *
     * Read off the source rather than the behaviour: a hand-written transition would answer just
     * as well, and the point of the pipeline is that no front end ever writes one.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function buildsNoneOfTheChainByHand(): void
    {
        foreach (self::frontEndClasses() as $class) {
            foreach (self::pipelineStages() as $stage) {
                $short = new ReflectionClass($stage)->getShortName();
                self::assertFalse(str_contains(self::sourceOf($class), "new {$short}"), "{$class} builds a {$short}.");
            }
        }
    }

    /** @return list<class-string> every stage a message passes through, first to last */
    private static function pipelineStages(): array
    {
        return [ResolvedThread::class, AnsweringTurn::class, CompletedTurn::class, FailedTurn::class];
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
            $meta = CompiledServe::meta();
            self::$injector = (new InjectorBuilder())(new ServeContext($meta), $meta);
        }

        return self::$injector;
    }

    /**
     * @param class-string $class
     *
     * @return string the file that class is written in
     *
     * @throws ReflectionException
     */
    private static function sourceOf(string $class): string
    {
        $file = new ReflectionClass($class)->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    /**
     * @return list<class-string> everything the command line front end is made of
     *
     * @throws ReflectionException
     */
    private static function frontEndClasses(): array
    {
        $namespace = new ReflectionClass(CliCommand::class)->getNamespaceName();
        $classes = [];
        foreach (self::sourceClasses() as $class) {
            $lives = new ReflectionClass($class)->getNamespaceName();
            if ($lives !== $namespace) {
                continue;
            }

            $classes[] = $class;
        }

        self::assertNotSame([], $classes);

        return $classes;
    }

    /**
     * @return list<class-string> every class this application ships, by PSR-4
     */
    private static function sourceClasses(): array
    {
        $root = dirname(__DIR__, levels: 2) . '/src';
        $files = glob("{$root}/{,*/,*/*/}*.php", flags: GLOB_BRACE);
        self::assertIsArray($files);
        self::assertNotSame([], $files);

        $classes = [];
        foreach ($files as $file) {
            $relative = substr($file, strlen("{$root}/"), -strlen('.php'));
            $class = AgentBridge::APP_NAME . '\\' . str_replace(search: '/', replace: '\\', subject: $relative);
            self::assertTrue(class_exists($class) || interface_exists($class), "{$file} holds no {$class}.");
            $classes[] = $class;
        }

        return $classes;
    }
}
