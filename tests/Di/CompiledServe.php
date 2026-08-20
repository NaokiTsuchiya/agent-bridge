<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Di;

use NaokiTsuchiya\RayDiContext\AppMeta;
use NaokiTsuchiya\RayDiContext\Exception\InvalidAppMeta;
use PHPUnit\Framework\Assert;

use function dirname;
use function escapeshellarg;
use function exec;
use function implode;
use function is_dir;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * The compiled scripts every injector case reads, produced the way a deployment produces them.
 *
 * The real `vendor/bin/ray-di-compile` is started as a process rather than `CompileRunner` being
 * called in-process: the CLI is what composer.json and the workflow invoke, and its argument
 * handling and exit status are part of what is under test. One compile serves the whole run — it
 * writes the same scripts every time, and the cases only read them.
 */
final class CompiledServe
{
    /** The bootstrap of the application as it ships. */
    private const string PRODUCTION_BOOTSTRAP = 'bootstrap.php';

    /** The bootstrap that maps the same context name onto the swapped execution layer. */
    private const string SPAWN_BOOTSTRAP = 'tests/Di/spawn-bootstrap.php';

    /** @var array<string, AppMeta> the compiles this process has already run, by bootstrap */
    private static array $metas = [];

    /**
     * Compiles into a throwaway app dir, once per test process.
     *
     * @return AppMeta the meta the compile was run with, to build a context from
     *
     * @throws InvalidAppMeta
     */
    public static function meta(): AppMeta
    {
        return self::compiled(self::PRODUCTION_BOOTSTRAP);
    }

    /**
     * The same, for the wiring whose execution layer is {@see SpawnServeContext}'s.
     *
     * A second app dir rather than a second context name: a process resolves from the compiled
     * scripts alone, so an app dir is the whole of what it takes to run this application on the
     * other execution layer.
     *
     * @return AppMeta the meta of the swapped compile
     *
     * @throws InvalidAppMeta
     */
    public static function spawnMeta(): AppMeta
    {
        return self::compiled(self::SPAWN_BOOTSTRAP);
    }

    /**
     * Runs the compile CLI against one of this repository's bootstraps.
     *
     * @param string $bootstrap which bootstrap to compile, relative to the project directory
     *
     * @return array{int, string} exit code and the combined output
     */
    public static function compile(string $appDir, string $bootstrap = self::PRODUCTION_BOOTSTRAP): array
    {
        $projectDir = dirname(__DIR__, levels: 2);
        $command = implode(' ', [
            'php',
            escapeshellarg("{$projectDir}/vendor/bin/ray-di-compile"),
            escapeshellarg("{$projectDir}/{$bootstrap}"),
            escapeshellarg($appDir),
            escapeshellarg(ServeContext::NAME),
        ]);

        $output = [];
        $exitCode = 0;
        exec("{$command} 2>&1", $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }

    /**
     * @param string $bootstrap which bootstrap to compile, relative to the project directory
     *
     * @return AppMeta the meta of that bootstrap's compile, run at most once per test process
     *
     * @throws InvalidAppMeta
     */
    private static function compiled(string $bootstrap): AppMeta
    {
        $known = self::$metas[$bootstrap] ?? null;
        if ($known !== null) {
            return $known;
        }

        $appDir = sys_get_temp_dir() . '/agent-bridge-compiled-serve-' . uniqid();
        Assert::assertTrue(mkdir($appDir, permissions: 0o777, recursive: true), message: "Could not create {$appDir}.");

        [$exitCode, $output] = self::compile($appDir, $bootstrap);
        Assert::assertSame(0, $exitCode, message: "Compiling {$bootstrap} failed: {$output}");

        $meta = AppMeta::fromAppDir($appDir, ServeContext::NAME);
        Assert::assertTrue(is_dir($meta->compileDir), message: "No compile dir at {$meta->compileDir}.");

        self::$metas[$bootstrap] = $meta;

        return $meta;
    }
}
