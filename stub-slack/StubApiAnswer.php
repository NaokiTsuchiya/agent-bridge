<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\StubSlack;

/**
 * What a `StubSlackServer` sends back for one Web API call.
 *
 * Kept apart from {@see StubSlackApi} for the same reason {@see StubSlackScenario} is kept apart
 * from {@see StubSlackServer}: this is a plain value handed to a `Response`, not something with
 * behaviour of its own.
 *
 * @internal
 */
final readonly class StubApiAnswer
{
    /**
     * @param int                   $status  the HTTP status the response is sent with
     * @param array<string, mixed>  $body    JSON-encoded as the response body
     * @param array<string, string> $headers sent in addition to `Content-Type`, e.g. `Retry-After`
     */
    public function __construct(
        public int $status = 200,
        public array $body = ['ok' => true],
        public array $headers = [],
    ) {}
}
