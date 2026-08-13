<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

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
 * a socket. {@see SlackApiEndpointTest} covers which environment turns into which endpoint; what is
 * asked here is only whether this provider hands that endpoint on.
 *
 * @internal
 */
final class SlackApiClientProviderTest extends TestCase
{
    /** A value shaped like a bot token; no workspace is ever reached with it. */
    private const string TOKEN = SlackBotToken::PREFIX . 'shaped-like-one';

    /** Any Web API method: the call is refused by the factory before a method matters. */
    private const string METHOD = 'auth.test';

    /** @var array<string, string|false> what the variables held before the case ran */
    private array $before = [];

    /** All three variables are process-wide, so a case that changed one must hand it back. */
    #[Override]
    protected function setUp(): void
    {
        foreach (self::variables() as $variable) {
            $this->before[$variable] = getenv($variable);
        }
    }

    /** Restores whatever was there, including its absence. */
    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->before as $variable => $value) {
            if ($value === false) {
                putenv($variable);

                continue;
            }

            putenv("{$variable}={$value}");
        }
    }

    /**
     * Nothing configured is the deployment there has been until now: Slack itself, over HTTPS.
     *
     * @throws SlackException
     */
    #[Test]
    public function callsSlackItselfWhenTheEndpointIsNotConfigured(): void
    {
        putenv(SlackApiEndpoint::HOST_VARIABLE);
        putenv(SlackApiEndpoint::PORT_VARIABLE);

        self::assertSame([['slack.com', 443]], self::callThrough());
    }

    /**
     * The point of the variables: the same client, aimed somewhere else.
     *
     * @throws SlackException
     */
    #[Test]
    public function callsWhereTheVariablesSayWhenTheyAreSet(): void
    {
        putenv(SlackApiEndpoint::HOST_VARIABLE . '=stub.internal');
        putenv(SlackApiEndpoint::PORT_VARIABLE . '=8443');

        self::assertSame([['stub.internal', 8443]], self::callThrough());
    }

    /**
     * A port that is not one stops the provider, rather than becoming a call to somewhere odd.
     *
     * The bot token is left unset on purpose: the endpoint is read first, so the refusal a person
     * meets is about the setting they got wrong.
     */
    #[Test]
    public function refusesToBuildAClientWhenThePortIsNotAPort(): void
    {
        putenv(SlackApiEndpoint::PORT_VARIABLE . '=not-a-port');
        putenv(SlackApiClientProvider::ENVIRONMENT_VARIABLE);

        $clients = new RecordingHttpClientFactory();

        try {
            new SlackApiClientProvider($clients, new RecordingSleeper(), new StreamingSettings())->get();
        } catch (SlackException $refusal) {
            self::assertStringContainsString(SlackApiEndpoint::PORT_VARIABLE, $refusal->getMessage());
            self::assertSame([], $clients->asked(), 'Nothing may be reached for.');

            return;
        }

        self::fail('The provider built a client for a port that is not one.');
    }

    /**
     * Builds the client the provider gives out and makes one call with it.
     *
     * @return list<array{string, int}> where that call asked for a connection
     *
     * @throws SlackException
     */
    private static function callThrough(): array
    {
        putenv(SlackApiClientProvider::ENVIRONMENT_VARIABLE . '=' . self::TOKEN);

        $clients = new RecordingHttpClientFactory();
        $api = new SlackApiClientProvider($clients, new RecordingSleeper(), new StreamingSettings())->get();

        // The factory refuses, so the call comes back as a failed result rather than reaching
        // anything; the endpoint has been recorded by then.
        $result = $api->call(self::METHOD, []);
        self::assertFalse($result->ok, 'A recorded call cannot have succeeded.');

        return $clients->asked();
    }

    /** @return list<string> every variable a case here may change */
    private static function variables(): array
    {
        return [
            SlackApiEndpoint::HOST_VARIABLE,
            SlackApiEndpoint::PORT_VARIABLE,
            SlackApiClientProvider::ENVIRONMENT_VARIABLE,
        ];
    }
}
