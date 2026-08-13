<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge;

use function is_array;
use function is_string;

/**
 * Typed reads out of decoded JSON, for data that arrives with no shape guarantee.
 *
 * Socket Mode frames, Web API bodies and the lines an agent's CLI prints are all JSON this app did
 * not build, and none of them is trusted: a key may be missing, or hold something of another type,
 * with the next release of whatever sent it. That is a normal case rather than an exception here, so
 * every read answers `null` and leaves it to the caller to decide what the absence means — which is
 * the one decision its callers must keep making the same way.
 *
 * @api
 */
final class Json
{
    /**
     * Whatever `json_decode` answered with, as an object, or null when it is anything else.
     *
     * Taking the decoded value as an argument rather than a variable is what keeps its type off a
     * `mixed` assignment, which is the whole reason the decode is not written inline.
     *
     * @return array<array-key, mixed>|null
     *
     * @pure
     */
    public static function asObject(mixed $decoded): ?array
    {
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @pure
     */
    public static function text(array $node, string $key): ?string
    {
        return is_string($node[$key] ?? null) ? $node[$key] : null;
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>|null
     *
     * @pure
     */
    public static function object(array $node, string $key): ?array
    {
        return is_array($node[$key] ?? null) ? $node[$key] : null;
    }
}
