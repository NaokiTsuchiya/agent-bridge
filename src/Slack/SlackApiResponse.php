<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;

/**
 * Reads the answer to a Web API call.
 *
 * Separate from the call itself for the same reason {@see ConnectionOpenResponse} is: the HTTP round
 * trip needs a workspace and a token, while deciding what the answer means is a judgement on a
 * string and a number — and that judgement is where the mistakes are. **Slack answers a refused call
 * with HTTP 200 and `ok: false`**, so an implementation that went by the status code would report
 * every failure as a success, and nothing downstream would ever fall back or complain.
 *
 * @api
 */
final class SlackApiResponse
{
    /** What a failure is called when the body does not say. */
    private const string UNKNOWN = 'unknown';

    /** The status a call over a tier's limit comes back with. */
    private const int TOO_MANY_REQUESTS = 429;

    /** What a rate limited call is called, whatever the body turns out to be. */
    private const string RATE_LIMITED = 'ratelimited';

    /** How long to wait for, as Slack asks; Swoole hands headers over with lowercase names. */
    private const string RETRY_AFTER = 'retry-after';

    /** How long to wait when a rate limited answer names no time of its own. */
    private const float DEFAULT_RETRY_AFTER = 1.0;

    /**
     * @param string                  $body    the response body, exactly as it came back
     * @param int                     $status  the HTTP status it came back with
     * @param array<array-key, mixed> $headers the response headers, by lowercase name
     */
    public static function of(string $body, int $status = 200, array $headers = []): SlackApiResult
    {
        // Decided on the status alone, before the body is looked at: a 429 does not have to be
        // Slack's own JSON — anything in front of the API may answer with a page of its own — and
        // the call has to be made again either way.
        if ($status === self::TOO_MANY_REQUESTS) {
            return new SlackApiResult(ok: false, error: self::RATE_LIMITED, retryAfter: self::secondsIn($headers));
        }

        $decoded = self::asObject(json_decode($body, associative: true));

        if ($decoded === null) {
            // Not Slack answering: a proxy's error page, a truncated body, an empty one.
            return new SlackApiResult(ok: false, error: 'the response is not a JSON object');
        }

        // `!== true` rather than a falsiness check: a body without `ok` is not a body that said yes.
        if (($decoded['ok'] ?? null) !== true) {
            return new SlackApiResult(ok: false, error: self::text($decoded, 'error') ?? self::UNKNOWN);
        }

        return new SlackApiResult(ok: true, ts: self::text($decoded, 'ts') ?? '');
    }

    /**
     * @param array<array-key, mixed> $headers as they came back
     *
     * @return float what Slack asked to be waited, or the default when it asked for nothing usable
     *
     * @pure
     */
    private static function secondsIn(array $headers): float
    {
        $asked = self::text($headers, self::RETRY_AFTER);

        if ($asked === null || !is_numeric($asked)) {
            return self::DEFAULT_RETRY_AFTER;
        }

        $seconds = (float) $asked;

        // A zero or a negative would turn the wait into a hot loop against a limit already reached.
        return $seconds > 0.0 ? $seconds : self::DEFAULT_RETRY_AFTER;
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
