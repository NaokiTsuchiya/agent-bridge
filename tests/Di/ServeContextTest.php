<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Di;

use Be\Framework\BecomingInterface;
use Be\Framework\Module\BeModule;
use BEAR\Resource\ResourceInterface;
use NaokiTsuchiya\AgentBridge\AgentBridge;
use NaokiTsuchiya\AgentBridge\Di\ServeContext;
use NaokiTsuchiya\AgentBridge\Runner\AgentRunner;
use NaokiTsuchiya\AgentBridge\Slack\SocketModeClient;
use NaokiTsuchiya\RayDiContext\CompiledContextInterface;
use NaokiTsuchiya\RayDiContext\ContextInterface;
use NaokiTsuchiya\RayDiContext\Exception\CompileDirUnavailable;
use NaokiTsuchiya\RayDiContext\Exception\InvalidAppMeta;
use NaokiTsuchiya\RayDiContext\Exception\WarmupNotCompiled;
use NaokiTsuchiya\RayDiContext\InjectorBuilder;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;
use Swoole\Runtime;

use function file_get_contents;
use function json_decode;
use function str_starts_with;

/**
 * The shape of the context itself, apart from what its compiled scripts can build.
 */
final class ServeContextTest extends TestCase
{
    /** Swoole's hook flags as they were before this class ran. */
    private static int $hookFlags = 0;

    /** {@inheritDoc} */
    #[Override]
    public static function setUpBeforeClass(): void
    {
        self::$hookFlags = Runtime::getHookFlags();
    }

    /** {@inheritDoc} */
    #[Override]
    public static function tearDownAfterClass(): void
    {
        Runtime::setHookFlags(self::$hookFlags);
    }

    /** The binding name Be publishes its namespace under. */
    private const string SEMANTIC_NAMESPACE = 'semantic_namespace';

    /**
     * The two methods the compile CLI and the boot sequence reach the context through.
     *
     * @throws InvalidAppMeta
     */
    #[Test]
    public function isAContext(): void
    {
        $context = new ServeContext(CompiledServe::meta());

        self::assertInstanceOf(ContextInterface::class, $context);
        self::assertInstanceOf(CompiledContextInterface::class, $context);
        self::assertInstanceOf(AbstractModule::class, $context());
    }

    /**
     * Be's module is the outer one, with this application's as its parent, so the compiler sees a
     * single module.
     *
     * @throws InvalidAppMeta
     * @throws ReflectionException
     */
    #[Test]
    public function composesBe(): void
    {
        $context = new ServeContext(CompiledServe::meta());

        self::assertInstanceOf(BeModule::class, $context());
    }

    /**
     * Read out of the compiled scripts rather than the module: what the running process gets is
     * what the compile wrote, and only that.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     */
    #[Test]
    public function pointsBeAtThisApplicationsSemanticNamespace(): void
    {
        $meta = CompiledServe::meta();
        $injector = (new InjectorBuilder())(new ServeContext($meta), $meta);

        self::assertSame(AgentBridge::SEMANTIC_NAMESPACE, $injector->getInstance('', self::SEMANTIC_NAMESPACE));
    }

    /**
     * The default is taken from the module's own signature rather than written out, so that this
     * keeps meaning what it means if Be ever changes it.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function doesNotLeaveBesDefaultNamespaceInPlace(): void
    {
        self::assertNotSame(
            new ReflectionParameter([BeModule::class, '__construct'], 'namespace')->getDefaultValue(),
            AgentBridge::SEMANTIC_NAMESPACE,
        );
    }

    /**
     * A compiled injector never unserializes an instance, so anything holding a resource is warmed up.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     * @throws WarmupNotCompiled
     */
    #[Test]
    public function warmsUpTheResourceLayerBeAndTheExecutionLayer(): void
    {
        $meta = CompiledServe::meta();
        $injector = (new InjectorBuilder())(new ServeContext($meta), $meta);
        $injector->warmup();

        self::assertInstanceOf(ResourceInterface::class, $injector->getInstance(ResourceInterface::class));
        self::assertInstanceOf(BecomingInterface::class, $injector->getInstance(BecomingInterface::class));
        self::assertInstanceOf(AgentRunner::class, $injector->getInstance(AgentRunner::class));
    }

    /**
     * Slack's client is #14's, and opening a connection at boot is the kind of thing a warmup list
     * quietly grows into. The namespace is read from a class in it rather than written out.
     *
     * @throws InvalidAppMeta
     * @throws ReflectionException
     */
    #[Test]
    public function warmsUpNothingOfSlacks(): void
    {
        $slack = new ReflectionClass(SocketModeClient::class)->getNamespaceName();
        $singletonsFile = CompiledServe::meta()->compileDir . '/singletons.json';
        self::assertFileExists($singletonsFile);
        $raw = file_get_contents($singletonsFile);
        self::assertIsString($raw);
        /** @var list<string> $singletons */
        $singletons = json_decode($raw, associative: true);
        self::assertIsArray($singletons);

        foreach ($singletons as $class) {
            self::assertIsString($class);
            self::assertFalse(str_starts_with($class, $slack), message: "{$class} is Slack's, and is warmed up.");
        }
    }
}
