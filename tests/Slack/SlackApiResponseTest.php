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
}
