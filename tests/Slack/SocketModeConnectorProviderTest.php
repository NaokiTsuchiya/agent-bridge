<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Slack\SlackApiEndpoint;
use NaokiTsuchiya\AgentBridge\Slack\SlackAppToken;
use NaokiTsuchiya\AgentBridge\Slack\SlackAppTokenFactory;
use NaokiTsuchiya\AgentBridge\Slack\SocketModeConnectorProvider;
use NaokiTsuchiya\AgentBridge\Slack\SocketModeException;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function getenv;
use function putenv;

/**
 * Where the connector the provider builds asks `apps.connections.open`.
 *
 * The handshake is started and gets no further than the first client: the factory records the host
 * and port and refuses, which is the one thing about the attempt this class is about. Which
 * environment turns into which endpoint is {@see SlackApiEndpointProviderTest}'s.
 *
 * @internal
 */
final class SocketModeConnectorProviderTest extends TestCase
{
    /** A value shaped like an app-level token; no workspace is ever reached with it. */
    private const string TOKEN = SlackAppToken::PREFIX . 'shaped-like-one';

    /** What the app token variable held before the case ran, put back afterwards. */
    private string|false $before = false;

    /** The variable is process-wide, so a case that changed it must hand it back. */
    #[Override]
    protected function setUp(): void
    {
        $this->before = getenv(SlackAppTokenFactory::ENVIRONMENT_VARIABLE);
    }

    /** Restores whatever was there, including its absence. */
    #[Override]
    protected function tearDown(): void
    {
        if ($this->before === false) {
            putenv(SlackAppTokenFactory::ENVIRONMENT_VARIABLE);

            return;
        }

        putenv(SlackAppTokenFactory::ENVIRONMENT_VARIABLE . "={$this->before}");
    }

    /**
     * The whole of what the endpoint is for: the same handshake, aimed where it says.
     *
     * A stub's host and port rather than Slack's, because the defaults of the connector are
     * Slack's: a provider that dropped the endpoint would still look right against those.
     *
     * @throws InvalidArgumentException never; the endpoint below is a usable one
     * @throws SocketModeException
     */
    #[Test]
    public function opensTheConnectionWhereTheEndpointSays(): void
    {
        putenv(SlackAppTokenFactory::ENVIRONMENT_VARIABLE . '=' . self::TOKEN);

        $clients = new RecordingHttpClientFactory();
        $connector = new SocketModeConnectorProvider($clients, new SlackApiEndpoint('stub.internal', 8443))->get();

        // The factory refuses, and the connector lets that out of `connect()`; by then the endpoint
        // it was going to open against has been recorded.
        try {
            $connector->connect();
        } catch (SocketModeException) {
            self::assertSame([['stub.internal', 8443]], $clients->asked());

            return;
        }

        self::fail('A connection was made without a workspace.');
    }
}
