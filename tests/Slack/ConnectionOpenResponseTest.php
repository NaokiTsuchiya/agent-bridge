<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the body of an `apps.connections.open` call is allowed to mean.
 *
 * The call needs a workspace; reading its answer does not, and that is where a wrong `ok` or a
 * missing `url` turns into either a clear failure or a crash.
 *
 * @internal
 */
final class ConnectionOpenResponseTest extends TestCase
{
    /**
     * The successful answer, shaped as Slack documents it.
     *
     * @throws SocketModeException
     */
    #[Test]
    public function returnsTheUrlOfASuccessfulResponse(): void
    {
        $body = '{"ok":true,"url":"wss://wss-primary.slack.com/link/?ticket=1234-5678&app_id=A01"}';

        self::assertSame(
            'wss://wss-primary.slack.com/link/?ticket=1234-5678&app_id=A01',
            ConnectionOpenResponse::websocketUrl($body),
        );
    }

    /**
     * A rejected token is the common failure, and the reason Slack gave has to survive to the log.
     *
     * @throws SocketModeException
     */
    #[Test]
    public function reportsTheErrorSlackNamed(): void
    {
        $this->expectException(SocketModeException::class);
        $this->expectExceptionMessageMatches('/invalid_auth/');

        ConnectionOpenResponse::websocketUrl('{"ok":false,"error":"invalid_auth"}');
    }

    /**
     * A failure whose reason cannot be read is still a failure, not a crash.
     *
     * @throws SocketModeException
     */
    #[DataProvider('failuresWithoutAReadableError')]
    #[Test]
    public function failsWhenTheResponseIsNotOk(string $body): void
    {
        $this->expectException(SocketModeException::class);

        ConnectionOpenResponse::websocketUrl($body);
    }

    /** @return iterable<string, array{string}> */
    public static function failuresWithoutAReadableError(): iterable
    {
        yield 'no error key' => ['{"ok":false}'];
        yield 'an error that is not a string' => ['{"ok":false,"error":42}'];
        yield 'ok as the string "true"' => ['{"ok":"true","url":"wss://wss-primary.slack.com/link/"}'];
        yield 'ok as 1' => ['{"ok":1,"url":"wss://wss-primary.slack.com/link/"}'];
        yield 'no ok at all' => ['{"url":"wss://wss-primary.slack.com/link/"}'];
    }

    /**
     * `ok` on its own is not a connection: without a usable url there is nothing to upgrade.
     *
     * @throws SocketModeException
     */
    #[DataProvider('successesWithoutAUsableUrl')]
    #[Test]
    public function failsWhenThereIsNoUrlToUse(string $body): void
    {
        $this->expectException(SocketModeException::class);

        ConnectionOpenResponse::websocketUrl($body);
    }

    /** @return iterable<string, array{string}> */
    public static function successesWithoutAUsableUrl(): iterable
    {
        yield 'missing' => ['{"ok":true}'];
        yield 'null' => ['{"ok":true,"url":null}'];
        yield 'a number' => ['{"ok":true,"url":42}'];
        yield 'an object' => ['{"ok":true,"url":{"host":"wss-primary.slack.com"}}'];
        yield 'empty' => ['{"ok":true,"url":""}'];
    }

    /**
     * A body that is not a JSON object at all is what a proxy or an outage answers with.
     *
     * @throws SocketModeException
     */
    #[DataProvider('bodiesThatAreNotObjects')]
    #[Test]
    public function failsWhenTheBodyIsNotAJsonObject(string $body): void
    {
        $this->expectException(SocketModeException::class);

        ConnectionOpenResponse::websocketUrl($body);
    }

    /** @return iterable<string, array{string}> */
    public static function bodiesThatAreNotObjects(): iterable
    {
        yield 'html' => ['<html>502 Bad Gateway</html>'];
        yield 'truncated json' => ['{"ok":true,'];
        // What the connector passes on when the client answered with no body at all.
        yield 'empty' => [''];
        yield 'a json array' => ['[]'];
        yield 'a json string' => ['"ok"'];
        yield 'a json boolean' => ['true'];
    }
}
