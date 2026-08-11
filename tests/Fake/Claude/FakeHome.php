<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Fake\Claude;

use function dirname;
use function file_put_contents;
use function getenv;
use function is_dir;
use function is_string;
use function mkdir;
use function sys_get_temp_dir;

use const FILE_APPEND;

/**
 * The directory the fake keeps everything under.
 *
 * It is replaceable through `FAKE_CLAUDE_HOME` because sessions and recordings are global state:
 * two tests pointed at the same root would see each other's sessions ("already in use" out of
 * nowhere) and each other's recordings. The fallback to the temp directory keeps the binary
 * usable by hand, which is how one debugs a failing contract test.
 */
final readonly class FakeHome
{
    /** @param string $path the root every session file and recording is written under */
    private function __construct(
        private string $path,
    ) {}

    /** Reads `FAKE_CLAUDE_HOME`, falling back to a fixed name under the temp directory. */
    public static function fromEnvironment(): self
    {
        $home = getenv('FAKE_CLAUDE_HOME');

        return new self(is_string($home) && $home !== '' ? $home : sys_get_temp_dir() . '/fake-claude-cli');
    }

    /** @param string $relative a path under the home, e.g. `turns.jsonl` */
    public function path(string $relative): string
    {
        return "{$this->path}/{$relative}";
    }

    /** Writes the file, creating whatever directories it needs. */
    public function write(string $relative, string $contents): void
    {
        $path = $this->path($relative);
        $this->ensureDirectory($path);
        file_put_contents($path, $contents);
    }

    /** Adds a line to the file, creating whatever directories it needs. */
    public function appendLine(string $relative, string $line): void
    {
        $path = $this->path($relative);
        $this->ensureDirectory($path);
        file_put_contents($path, "{$line}\n", flags: FILE_APPEND);
    }

    /** @param string $path a file path whose directory may not exist yet */
    private function ensureDirectory(string $path): void
    {
        $directory = dirname($path);
        $exists = is_dir($directory);
        if ($exists) {
            return;
        }

        mkdir($directory, permissions: 0o777, recursive: true);
    }
}
