<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Support;

use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;

/**
 * Typed reads out of decoded JSON, for data that arrives with no shape guarantee.
 *
 * Both sides of this suite consume JSON they did not build: the fake reads scenario files and
 * stdin lines a test wrote by hand, and the tests read lines a process printed. A missing or
 * wrongly-typed key is a normal case there, not an exception, so every reader answers `null`
 * and lets the caller decide what the absence means.
 */
final class Json
{
    /** @return array<array-key, mixed>|null null when the line is not a JSON object at all */
    public static function decode(string $line): ?array
    {
        /** @var array<array-key, mixed>|bool|float|int|string|null $decoded */
        $decoded = json_decode($line, associative: true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @pure
     */
    public static function text(array $node, string|int $key): ?string
    {
        return is_string($node[$key] ?? null) ? $node[$key] : null;
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @pure
     */
    public static function integer(array $node, string|int $key): ?int
    {
        return is_int($node[$key] ?? null) ? $node[$key] : null;
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @pure
     */
    public static function flag(array $node, string|int $key): ?bool
    {
        return is_bool($node[$key] ?? null) ? $node[$key] : null;
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed> empty when the key is absent or holds something else
     *
     * @pure
     */
    public static function node(array $node, string|int $key): array
    {
        return is_array($node[$key] ?? null) ? $node[$key] : [];
    }
}
