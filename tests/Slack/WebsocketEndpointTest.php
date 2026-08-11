<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use NaokiTsuchiya\AgentBridge\Slack\SocketModeException;
use NaokiTsuchiya\AgentBridge\Slack\WebsocketEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Splitting the WSS URL Slack hands out into what a coroutine client is opened with.
 *
 * @internal
 */
final class WebsocketEndpointTest extends TestCase
{
    /**
     * The ticket lives in the query string, so dropping it would make every upgrade fail.
     *
     * @throws SocketModeException
     */
    #[Test]
    public function keepsTheQueryOnThePath(): void
    {
        $endpoint = WebsocketEndpoint::fromUrl('wss://wss-primary.slack.com/link/?ticket=1234-5678&app_id=A01');

        self::assertSame('wss-primary.slack.com', $endpoint->host);
        self::assertSame(443, $endpoint->port);
        self::assertSame('/link/?ticket=1234-5678&app_id=A01', $endpoint->path);
    }

    /**
     * A URL that names a port means that port, not the default.
     *
     * @throws SocketModeException
     */
    #[Test]
    public function takesThePortFromTheUrlWhenThereIsOne(): void
    {
        $endpoint = WebsocketEndpoint::fromUrl('wss://wss-primary.slack.com:8443/link');

        self::assertSame(8443, $endpoint->port);
        self::assertSame('/link', $endpoint->path);
    }

    /**
     * A URL with no path at all still has to be upgraded somewhere.
     *
     * @throws SocketModeException
     */
    #[Test]
    public function fallsBackToTheRootPath(): void
    {
        $endpoint = WebsocketEndpoint::fromUrl('wss://wss-primary.slack.com');

        self::assertSame('/', $endpoint->path);
        self::assertSame(443, $endpoint->port);
    }

    /**
     * The ticket is a credential: anything but WSS would put it on the wire in the clear.
     *
     * @throws SocketModeException
     */
    #[DataProvider('urlsThatAreNotWss')]
    #[Test]
    public function refusesAnythingThatIsNotWss(string $url): void
    {
        $this->expectException(SocketModeException::class);

        WebsocketEndpoint::fromUrl($url);
    }

    /** @return iterable<string, array{string}> */
    public static function urlsThatAreNotWss(): iterable
    {
        yield 'plain websocket' => ['ws://wss-primary.slack.com/link'];
        yield 'https' => ['https://wss-primary.slack.com/link'];
        yield 'http' => ['http://wss-primary.slack.com/link'];
        yield 'a scheme that merely starts the same' => ['wssx://wss-primary.slack.com/link'];
    }

    /**
     * Without a host there is nothing to connect to, however well-formed the rest looks.
     *
     * @throws SocketModeException
     */
    #[DataProvider('urlsWithoutAHost')]
    #[Test]
    public function refusesAUrlThatNamesNoHost(string $url): void
    {
        $this->expectException(SocketModeException::class);

        WebsocketEndpoint::fromUrl($url);
    }

    /** @return iterable<string, array{string}> */
    public static function urlsWithoutAHost(): iterable
    {
        yield 'a bare path' => ['/link/?ticket=1234-5678'];
        yield 'an empty authority' => ['wss:///link'];
        yield 'empty' => [''];
        // A port that is not a number is the one shape `parse_url` gives up on outright.
        yield 'unparseable' => ['wss://wss-primary.slack.com:port/link'];
    }
}
