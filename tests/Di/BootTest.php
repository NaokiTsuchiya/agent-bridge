<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Di;

use NaokiTsuchiya\AgentBridge\Di\Boot;
use NaokiTsuchiya\AgentBridge\Di\BootException;
use NaokiTsuchiya\AgentBridge\Di\ServeContext;
use NaokiTsuchiya\AgentBridge\Tests\Support\TempDir;
use NaokiTsuchiya\RayDiContext\AppMeta;
use NaokiTsuchiya\RayDiContext\CompiledWarmableInjector;
use NaokiTsuchiya\RayDiContext\Exception\CompileDirUnavailable;
use NaokiTsuchiya\RayDiContext\Exception\ExceptionInterface;
use NaokiTsuchiya\RayDiContext\Exception\InvalidAppMeta;
use NaokiTsuchiya\RayDiContext\Exception\WarmupNotCompiled;
use NaokiTsuchiya\RayDiContext\MapContextProvider;
use NaokiTsuchiya\RayDiContext\RuntimeWarmableInjector;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function mkdir;

/**
 * The boot sequence, driven against test contexts so that its lifecycle guarantees are observable:
 * the context is resolved once, the tmp dir exists, and injectors are built and warmed up.
 *
 * @mago-expect lint:too-many-methods
 */
final class BootTest extends TestCase
{
    /** The throwaway app dir of the case being run. */
    private string $appDir = '';

    /** Every case looks at directories, so none of them may share one. */
    #[Override]
    protected function setUp(): void
    {
        $this->appDir = TempDir::make('boot');
    }

    /** Leaves nothing behind under the system temporary directory. */
    #[Override]
    protected function tearDown(): void
    {
        TempDir::remove($this->appDir);
    }

    /**
     * The boot process asks the provider for the context exactly once.
     *
     * @throws BootException
     * @throws ExceptionInterface
     */
    #[Test]
    public function asksForTheContextExactlyOnce(): void
    {
        $context = new SpyContext();

        (new Boot($this->meta(), $context))();

        self::assertSame(1, $context->contextCalls);
    }

    /**
     * A non-compiled context receives a runtime injector whose warmup is a no-op.
     *
     * @throws BootException
     * @throws ExceptionInterface
     */
    #[Test]
    public function buildsAndWarmsUpRuntimeInjector(): void
    {
        $context = new SpyContext();

        $injector = (new Boot($this->meta(), $context))();

        self::assertInstanceOf(RuntimeWarmableInjector::class, $injector);
    }

    /**
     * A compiled context with valid scripts builds a compiled injector and executes warmup.
     *
     * @throws BootException
     * @throws ExceptionInterface
     */
    #[Test]
    public function buildsAndWarmsUpCompiledInjector(): void
    {
        $meta = CompiledServe::meta();
        $provider = new MapContextProvider([ServeContext::NAME => ServeContext::class]);

        $injector = (new Boot($meta, $provider))();

        self::assertInstanceOf(CompiledWarmableInjector::class, $injector);
    }

    /**
     * A compiled context refuses to start when its compile directory does not exist.
     *
     * @throws BootException
     * @throws ExceptionInterface
     */
    #[Test]
    public function refusesToStartWhenCompileDirIsUnavailable(): void
    {
        $meta = $this->meta();
        $context = new SpyCompiledContext();

        $this->expectException(CompileDirUnavailable::class);

        (new Boot($meta, $context))();
    }

    /**
     * A compiled context refuses to start when its compile directory holds no singleton metadata.
     *
     * @throws BootException
     * @throws ExceptionInterface
     */
    #[Test]
    public function refusesToStartWhenCompiledScriptsLackSingletonMetadata(): void
    {
        $meta = $this->meta();
        self::assertTrue(mkdir($meta->compileDir, permissions: 0o755, recursive: true));
        $context = new SpyCompiledContext();

        $this->expectException(WarmupNotCompiled::class);

        (new Boot($meta, $context))();
    }

    /**
     * Context resolution failure propagates to the caller.
     *
     * @throws BootException
     * @throws ExceptionInterface
     */
    #[Test]
    public function propagatesContextResolutionFailure(): void
    {
        $meta = $this->meta();
        $provider = new MapContextProvider([]);

        $this->expectException(ExceptionInterface::class);

        (new Boot($meta, $provider))();
    }

    /**
     * The default tmp dir is three levels below the app dir, so this is also what shows the
     * directory is created recursively.
     *
     * @throws BootException
     * @throws ExceptionInterface
     */
    #[Test]
    public function createsTheTmpDirWhenNothingOnTheWayToItExists(): void
    {
        $meta = $this->meta();

        (new Boot($meta, new SpyContext()))();

        self::assertDirectoryExists($meta->tmpDir);
    }

    /**
     * The shallow case, separated from the one above so that dropping the recursion is visible in
     * exactly one of the two.
     *
     * @throws BootException
     * @throws ExceptionInterface
     */
    #[Test]
    public function createsTheTmpDirWhenOnlyItsParentExists(): void
    {
        $meta = $this->meta();
        self::assertTrue(mkdir("{$this->appDir}/var/tmp", permissions: 0o755, recursive: true));

        (new Boot($meta, new SpyContext()))();

        self::assertDirectoryExists($meta->tmpDir);
    }

    /**
     * A restart finds the directory already there; creating it unconditionally would fail here.
     *
     * @throws BootException
     * @throws ExceptionInterface
     */
    #[Test]
    public function leavesAnExistingTmpDirAlone(): void
    {
        $meta = $this->meta();
        self::assertTrue(mkdir($meta->tmpDir, permissions: 0o755, recursive: true));
        $marker = "{$meta->tmpDir}/kept";
        file_put_contents($marker, data: 'kept');

        (new Boot($meta, new SpyContext()))();

        self::assertFileExists($marker);
    }

    /**
     * Ray.Di answers a missing tmp dir by falling back to the system temporary directory without
     * saying so, so a boot that shrugged this off would look like it had worked.
     *
     * @throws BootException
     * @throws ExceptionInterface
     */
    #[Test]
    public function refusesToStartWhenTheTmpDirCannotBeCreated(): void
    {
        $meta = $this->meta();
        self::assertTrue(mkdir("{$this->appDir}/var/tmp", permissions: 0o755, recursive: true));
        file_put_contents($meta->tmpDir, data: 'a file where the directory belongs');

        $this->expectException(BootException::class);

        (new Boot($meta, new SpyContext()))();
    }

    /**
     * The meta of this case's app dir, with the same defaults the compile command uses.
     *
     * @throws InvalidAppMeta
     */
    private function meta(): AppMeta
    {
        return AppMeta::fromAppDir($this->appDir, ServeContext::NAME);
    }
}
