<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Di;

use NaokiTsuchiya\AgentBridge\Di\ServeContext;
use NaokiTsuchiya\AgentBridge\Tests\Support\Json;
use NaokiTsuchiya\AgentBridge\Tests\Support\TempDir;
use NaokiTsuchiya\RayDiContext\BakedPathGuard;
use NaokiTsuchiya\RayDiContext\CompileRunner;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionParameter;

use function count;
use function dirname;
use function file_get_contents;
use function glob;
use function str_contains;

/**
 * The command a deployment and the workflow run to produce the compiled scripts.
 *
 * The CLI is started as a process rather than `CompileRunner` being called directly: what has to
 * hold is that *this* invocation, with these arguments, exits 0 — the argument handling and the
 * exit-status mapping are the part a mistake would hide in.
 */
final class CompileCommandTest extends TestCase
{
    /** The throwaway app dir compiled into. */
    private string $appDir = '';

    /** Compiled into a directory of its own so that nothing is read back from an earlier run. */
    #[Override]
    protected function setUp(): void
    {
        $this->appDir = TempDir::make('compile');
    }

    /** Leaves nothing behind under the system temporary directory. */
    #[Override]
    protected function tearDown(): void
    {
        TempDir::remove($this->appDir);
    }

    /** Exit 0 and scripts on disk: the two halves of "the compile ran". */
    #[Test]
    public function compilesTheServeContext(): void
    {
        [$exitCode, $output] = CompiledServe::compile($this->appDir);

        self::assertSame(0, $exitCode, message: $output);
        $scripts = glob($this->appDir . '/var/di/' . ServeContext::NAME . '/*.php');
        self::assertNotFalse($scripts);
        self::assertGreaterThan(0, count($scripts));
    }

    /**
     * The CLI builds its runner from the defaults, so the guard being one of them is what makes the
     * exit 0 above mean "nothing that belongs to the running machine was frozen into a script".
     *
     * @throws ReflectionException
     */
    #[Test]
    public function guardsAgainstBakedPathsWhileCompiling(): void
    {
        self::assertInstanceOf(
            BakedPathGuard::class,
            new ReflectionParameter([CompileRunner::class, '__construct'], 'bakedPathGuard')->getDefaultValue(),
        );
    }

    /** The command has to be somewhere a person and the workflow can both find it. */
    #[Test]
    public function isReachableThroughComposer(): void
    {
        $composer = Json::decode((string) file_get_contents(dirname(__DIR__, levels: 2) . '/composer.json')) ?? [];
        $compile = Json::text(Json::node($composer, 'scripts'), 'compile');
        self::assertIsString($compile);

        self::assertStringContainsString('ray-di-compile', $compile);
        self::assertStringContainsString('bootstrap.php', $compile);
        // The context name decides which directory the scripts land in, and the server looks in the
        // one this constant names: compiling some other context would leave it with nothing.
        self::assertStringContainsString(ServeContext::NAME, $compile);
    }

    /** A compile nobody runs is a compile that breaks unnoticed. */
    #[Test]
    public function runsInContinuousIntegration(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, levels: 2) . '/.github/workflows/ci.yml');

        self::assertTrue(str_contains($workflow, 'composer compile'));
    }
}
