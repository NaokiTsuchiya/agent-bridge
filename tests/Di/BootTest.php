<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Di;

use Be\Framework\BecomingInterface;
use BEAR\Resource\ResourceInterface;
use NaokiTsuchiya\AgentBridge\Di\Boot;
use NaokiTsuchiya\AgentBridge\Di\BootException;
use NaokiTsuchiya\AgentBridge\Di\ServeContext;
use NaokiTsuchiya\AgentBridge\Tests\Support\TempDir;
use NaokiTsuchiya\RayDiContext\AppMeta;
use NaokiTsuchiya\RayDiContext\Exception\ExceptionInterface;
use NaokiTsuchiya\RayDiContext\Exception\InvalidAppMeta;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function mkdir;

/**
 * The boot sequence, driven against a spy context so that the two things it must get right are
 * observable: the injector is asked for once, and the tmp dir exists afterwards.
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
     * The contract allows a different instance per call, so a second call would quietly hand the
     * process an injector nothing had been warmed up in.
     *
     * @throws BootException
     * @throws ExceptionInterface
     */
    #[Test]
    public function asksForTheInjectorExactlyOnce(): void
    {
        $context = new SpyContext([ResourceInterface::class, BecomingInterface::class]);

        (new Boot($this->meta(), $context))();

        self::assertSame(1, $context->injectorCalls);
    }

    /**
     * The warmup is what the list is for; naming classes and never building them is the failure
     * this case exists to catch.
     *
     * @throws BootException
     * @throws ExceptionInterface
     */
    #[Test]
    public function warmsUpEverythingTheContextNames(): void
    {
        $context = new SpyContext([ResourceInterface::class, BecomingInterface::class]);

        (new Boot($this->meta(), $context))();

        self::assertSame([ResourceInterface::class, BecomingInterface::class], $context->injector->asked);
    }

    /**
     * The other side of the same rule: nothing is built that the context did not ask for.
     *
     * @throws BootException
     * @throws ExceptionInterface
     */
    #[Test]
    public function warmsUpNothingWhenTheContextNamesNothing(): void
    {
        $context = new SpyContext();

        (new Boot($this->meta(), $context))();

        self::assertSame([], $context->injector->asked);
    }

    /**
     * Warming up one injector and returning another would leave the warmup behind.
     *
     * @throws BootException
     * @throws ExceptionInterface
     */
    #[Test]
    public function handsBackTheInjectorItWarmedUp(): void
    {
        $context = new SpyContext([ResourceInterface::class]);

        $injector = (new Boot($this->meta(), $context))();

        self::assertSame($context->injector, $injector);
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
