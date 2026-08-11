<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Fake\Claude;

use function array_filter;
use function array_values;
use function file_get_contents;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function sha1;

/**
 * The fake's stand-in for the transcripts the real CLI keeps under `~/.claude/projects`.
 *
 * Sessions are keyed by **the working directory and the UUID together**, because the real CLI
 * files a transcript under a slug of the project directory: the same UUID started in two
 * directories is two unrelated sessions there, and has to be here too, or a test that changes
 * directory would silently keep the old context.
 *
 * Only the inputs are kept. A reply is derived from them, so there is nothing else worth storing.
 */
final readonly class SessionStore
{
    /** @param string $cwd the directory a process was started in, which scopes its sessions */
    public function __construct(
        private FakeHome $home,
        private string $cwd,
    ) {}

    /** Whether this cwd has that session, which is what `--resume` and `--session-id` turn on. */
    public function exists(string $uuid): bool
    {
        $path = $this->home->path($this->relativePath($uuid));

        return is_file($path);
    }

    /** Starts an empty session, so that a later `--session-id` for the same UUID is refused. */
    public function create(string $uuid): void
    {
        $this->home->write($this->relativePath($uuid), '[]');
    }

    /**
     * The inputs this session has been sent so far, oldest first.
     *
     * @return list<string>
     */
    public function history(string $uuid): array
    {
        $path = $this->home->path($this->relativePath($uuid));
        $exists = is_file($path);
        $raw = $exists ? file_get_contents($path) : false;
        if (!is_string($raw)) {
            return [];
        }

        /** @var array<array-key, mixed>|bool|float|int|string|null $decoded */
        $decoded = json_decode($raw, associative: true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, is_string(...)));
    }

    /** Adds one turn's input, which is what the following turn can look back on. */
    public function append(string $uuid, string $input): void
    {
        $json = json_encode([...$this->history($uuid), $input]);
        $this->home->write($this->relativePath($uuid), $json === false ? '[]' : $json);
    }

    /** The two keys of a session, in one path: the working directory and the UUID. */
    private function relativePath(string $uuid): string
    {
        return 'sessions/' . sha1($this->cwd) . "/{$uuid}.json";
    }
}
