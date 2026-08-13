<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Slack\SlackApiClientProvider;
use NaokiTsuchiya\AgentBridge\Slack\SlackApiEndpoint;
use NaokiTsuchiya\AgentBridge\Slack\SlackBotToken;
use NaokiTsuchiya\AgentBridge\Slack\SlackException;
use NaokiTsuchiya\AgentBridge\Slack\StreamingSettings;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function getenv;
use function putenv;

/**
 * Where the Web API client the provider builds actually sends its calls.
 *
 * The client is asked to make one call, because that is when it opens a connection: the factory it
 * is given records the host and port instead of connecting, so a case can see the endpoint without
 * a socket. {@see SlackApiEndpointProviderTest} covers which environment turns into which endpoint;
 * what is asked here is only whether this provider hands the endpoint it was given on.
 *
 * @internal
 */
final class SlackApiClientProviderTest extends TestCase
{
    /** A value shaped like a bot token; no workspace is ever reached with it. */
    private const string TOKEN = SlackBotToken::PREFIX . 'shaped-like-one';

    /** Any Web API method: the call is refused by the factory before a method matters. */
    private const string METHOD = 'auth.test';

    /** What the bot token variable held before the case ran, put back afterwards. */
    private string|false $before = false;

    /** The variable is process-wide, so a case that changed it must hand it back. */
    #[Override]
    protected function setUp(): void
    {
        $this->before = getenv(SlackApiClientProvider::ENVIRONMENT_VARIABLE);
    }

    /** Restores whatever was there, including its absence. */
    #[Override]
    protected function tearDown(): void
    {
        if ($this->before === false) {
            putenv(SlackApiClientProvider::ENVIRONMENT_VARIABLE);

            return;
        }

        putenv(SlackApiClientProvider::ENVIRONMENT_VARIABLE . "={$this->before}");
    }

    /**
     * The whole of what the endpoint is for: the same client, aimed where it says.
     *
     * A stub's host and port rather than Slack's, because the defaults of the transport are
     * Slack's: a provider that dropped the endpoint would still look right against those.
     *
     * @throws InvalidArgumentException never; the endpoint below is a usable one
     * @throws SlackException
     */
    #[Test]
    public function callsWhereTheEndpointSays(): void
    {
        putenv(SlackApiClientProvider::ENVIRONMENT_VARIABLE . '=' . self::TOKEN);

        $clients = new RecordingHttpClientFactory();
        $api = new SlackApiClientProvider(
            $clients,
            new SlackApiEndpoint('stub.internal', 8443),
            new RecordingSleeper(),
            new StreamingSettings(),
        )->get();

        // The factory refuses, so the call comes back as a failed result rather than reaching
        // anything; the endpoint has been recorded by then.
        $result = $api->call(self::METHOD, []);

        self::assertFalse($result->ok, 'A recorded call cannot have succeeded.');
        self::assertSame([['stub.internal', 8443]], $clients->asked());
    }
}
