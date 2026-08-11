<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Di;

use NaokiTsuchiya\AgentBridge\Di\ServeContext;
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
    /** The meta of the compile this process has already run, if it has. */
    private static ?AppMeta $meta = null;

    /**
     * Compiles into a throwaway app dir, once per test process.
     *
     * @return AppMeta the meta the compile was run with, to build a context from
     *
     * @throws InvalidAppMeta
     */
    public static function meta(): AppMeta
    {
        if (self::$meta !== null) {
            return self::$meta;
        }

        $appDir = sys_get_temp_dir() . '/agent-bridge-compiled-serve-' . uniqid();
        Assert::assertTrue(mkdir($appDir, permissions: 0o777, recursive: true), message: "Could not create {$appDir}.");

        [$exitCode, $output] = self::compile($appDir);
        Assert::assertSame(0, $exitCode, message: "Compiling the serve context failed: {$output}");

        $meta = AppMeta::fromAppDir($appDir, ServeContext::NAME);
        Assert::assertTrue(is_dir($meta->compileDir), message: "No compile dir at {$meta->compileDir}.");

        self::$meta = $meta;

        return $meta;
    }

    /**
     * Runs the compile CLI against this repository's bootstrap.
     *
     * @return array{int, string} exit code and the combined output
     */
    public static function compile(string $appDir): array
    {
        $projectDir = dirname(__DIR__, levels: 2);
        $command = implode(' ', [
            'php',
            escapeshellarg("{$projectDir}/vendor/bin/ray-di-compile"),
            escapeshellarg("{$projectDir}/bootstrap.php"),
            escapeshellarg($appDir),
            escapeshellarg(ServeContext::NAME),
        ]);

        $output = [];
        $exitCode = 0;
        exec("{$command} 2>&1", $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }
}
