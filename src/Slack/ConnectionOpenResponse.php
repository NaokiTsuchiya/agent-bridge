<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use function is_array;
use function is_string;
use function json_decode;

/**
 * Reads the body of an `apps.connections.open` call.
 *
 * Separate from the call itself: the HTTP round trip needs a real workspace, but deciding what the
 * body means is a judgement on a string, and that judgement is where the mistakes are.
 *
 * @api
 */
final class ConnectionOpenResponse
{
    /**
     * The WSS URL the body carries.
     *
     * `ok` is checked before `url` on purpose: Slack answers a bad token with `ok: false` and no
     * `url` at all, and reading `url` first would turn a plain `invalid_auth` into a type error.
     *
     * @throws SocketModeException when the body is not a successful response carrying a URL
     */
    public static function websocketUrl(string $body): string
    {
        $decoded = self::asObject(json_decode($body, associative: true));

        if ($decoded === null) {
            throw new SocketModeException('apps.connections.open did not answer with a JSON object.');
        }

        if (($decoded['ok'] ?? null) !== true) {
            $error = is_string($decoded['error'] ?? null) ? $decoded['error'] : 'unknown';

            throw new SocketModeException("apps.connections.open failed: {$error}.");
        }

        $url = is_string($decoded['url'] ?? null) ? $decoded['url'] : '';

        if ($url === '') {
            throw new SocketModeException('apps.connections.open answered ok without a url.');
        }

        return $url;
    }

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
    private static function asObject(mixed $decoded): ?array
    {
        return is_array($decoded) ? $decoded : null;
    }
}
