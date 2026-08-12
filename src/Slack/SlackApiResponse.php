<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use function is_array;
use function is_string;
use function json_decode;

/**
 * Reads the body of a Web API call.
 *
 * Separate from the call itself for the same reason {@see ConnectionOpenResponse} is: the HTTP round
 * trip needs a workspace and a token, while deciding what the body means is a judgement on a string
 * — and that judgement is where the mistakes are. **Slack answers a refused call with HTTP 200 and
 * `ok: false`**, so an implementation that went by the status code would report every failure as a
 * success, and nothing downstream would ever fall back or complain.
 *
 * @api
 */
final class SlackApiResponse
{
    /** What a failure is called when the body does not say. */
    private const string UNKNOWN = 'unknown';

    /** @param string $body the response body, exactly as it came back */
    public static function of(string $body): SlackApiResult
    {
        $decoded = self::asObject(json_decode($body, associative: true));

        if ($decoded === null) {
            // Not Slack answering: a proxy's error page, a truncated body, an empty one.
            return new SlackApiResult(ok: false, error: 'the response is not a JSON object');
        }

        // `!== true` rather than a falsiness check: a body without `ok` is not a body that said yes.
        if (($decoded['ok'] ?? null) !== true) {
            return new SlackApiResult(ok: false, error: self::text($decoded, 'error') ?? self::UNKNOWN);
        }

        return new SlackApiResult(ok: true);
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

    /**
     * @param array<array-key, mixed> $node
     *
     * @pure
     */
    private static function text(array $node, string $key): ?string
    {
        return is_string($node[$key] ?? null) ? $node[$key] : null;
    }
}
