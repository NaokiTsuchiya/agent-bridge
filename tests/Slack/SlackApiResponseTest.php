<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use NaokiTsuchiya\AgentBridge\Slack\SlackApiResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a Web API response body means.
 *
 * This is the one judgement on the way out that a fake client cannot stand in for: a fake answers
 * with a result, so an implementation that read a refusal as a success would pass every other case
 * in this directory. **Slack refuses a call with HTTP 200 and `ok: false`**, which is exactly the
 * shape that tempts an implementation to go by the status code.
 */
final class SlackApiResponseTest extends TestCase
{
    /** The one body that means the call was carried out. */
    #[Test]
    public function readsASuccess(): void
    {
        $result = SlackApiResponse::of('{"ok":true,"ts":"1700000001.123456"}');

        self::assertTrue($result->ok);
        self::assertSame('', $result->error);
    }

    /** A refusal arrives as a perfectly good response that says no. */
    #[Test]
    public function readsARefusalAndWhyItWasRefused(): void
    {
        $result = SlackApiResponse::of('{"ok":false,"error":"channel_not_found"}');

        self::assertFalse($result->ok);
        self::assertSame('channel_not_found', $result->error);
    }

    /**
     * Everything that is not a body saying yes.
     *
     * @param string $body what came back
     */
    #[DataProvider('refusals')]
    #[Test]
    public function readsAnythingElseAsAFailure(string $body): void
    {
        self::assertFalse(SlackApiResponse::of($body)->ok);
    }

    /** @return iterable<string, array{string}> */
    public static function refusals(): iterable
    {
        yield 'a body that never says ok' => ['{"warning":"superfluous_charset"}'];
        yield 'a body that says ok in another form' => ['{"ok":"true"}'];
        yield 'a body that says no' => ['{"ok":false}'];
        yield 'an error page from something in between' => ['<html><body>500</body></html>'];
        yield 'nothing at all' => [''];
        yield 'a JSON value that is not an object' => ['"ok"'];
    }

    /** A failure always carries something to put in a log, even when the body did not say what. */
    #[Test]
    public function alwaysHasSomethingToSay(): void
    {
        self::assertNotSame('', SlackApiResponse::of('{"ok":false}')->error);
        self::assertNotSame('', SlackApiResponse::of('<html></html>')->error);
    }

    /** The message a stream was opened as, which is what everything after it is addressed to. */
    #[Test]
    public function readsTheMessageACallCreated(): void
    {
        self::assertSame('1700000001.123456', SlackApiResponse::of('{"ok":true,"ts":"1700000001.123456"}')->ts);
        self::assertSame('', SlackApiResponse::of('{"ok":true}')->ts);
    }

    /** A rate limit says how long to wait in a header, and the wait is what makes it recoverable. */
    #[Test]
    public function readsHowLongARateLimitAsksFor(): void
    {
        $result = SlackApiResponse::of('{"ok":false,"error":"ratelimited"}', 429, ['retry-after' => '2']);

        self::assertFalse($result->ok);
        self::assertSame(2.0, $result->retryAfter);
    }

    /**
     * A 429 is a 429 whatever the body turns out to be.
     *
     * Anything between this process and Slack — a proxy, a load balancer — may answer a limit with
     * a page of its own, and an implementation that went looking for `error: ratelimited` in the
     * body would read that as an ordinary failure and never try again.
     *
     * @param string                  $body    what came back with the 429
     * @param array<string, string>   $headers the headers it came back with
     * @param float                   $seconds how long the answer means to wait
     */
    #[DataProvider('rateLimits')]
    #[Test]
    public function alwaysHasAWaitForARateLimit(string $body, array $headers, float $seconds): void
    {
        $result = SlackApiResponse::of($body, 429, $headers);

        self::assertFalse($result->ok);
        self::assertSame($seconds, $result->retryAfter);
    }

    /** @return iterable<string, array{string, array<string, string>, float}> */
    public static function rateLimits(): iterable
    {
        yield 'as Slack sends it' => ['{"ok":false,"error":"ratelimited"}', ['retry-after' => '30'], 30.0];
        yield 'from something in between' => ['<html>429</html>', ['retry-after' => '5'], 5.0];
        yield 'with no header at all' => ['{"ok":false}', [], 1.0];
        yield 'with a header that is not a number' => ['{"ok":false}', ['retry-after' => 'soon'], 1.0];
        yield 'with a header that asks for nothing' => ['{"ok":false}', ['retry-after' => '0'], 1.0];
    }

    /** Every other answer asks for no wait at all, which is what tells the two apart downstream. */
    #[Test]
    public function asksForNoWaitOnAnythingElse(): void
    {
        self::assertSame(0.0, SlackApiResponse::of('{"ok":false,"error":"channel_not_found"}')->retryAfter);
        self::assertSame(0.0, SlackApiResponse::of('{"ok":true}')->retryAfter);
    }
}
