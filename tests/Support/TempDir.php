<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Support;

use FilesystemIterator;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Throwaway directories for tests that need a real one on disk.
 *
 * Both things this suite isolates are directories: a session store is keyed by the working
 * directory a process was started in, and the fake's state root is a directory of its own. Two
 * tests that shared either would see each other's sessions and recordings.
 */
final class TempDir
{
    /** @param string $prefix names the directory after the test that asked for it */
    public static function make(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/agent-bridge-' . $prefix . '-' . uniqid();
        Assert::assertTrue(mkdir($path, permissions: 0o777, recursive: true), message: "Could not create {$path}.");

        return $path;
    }

    /** Deletes the tree; does nothing when it is already gone. */
    public static function remove(string $path): void
    {
        $exists = is_dir($path);
        if (!$exists) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $target = (string) $entry;
            $isDirectory = is_dir($target);
            if ($isDirectory) {
                rmdir($target);
                continue;
            }

            unlink($target);
        }

        rmdir($path);
    }
}
