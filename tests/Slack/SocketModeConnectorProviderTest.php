<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use NaokiTsuchiya\AgentBridge\Slack\SlackApiEndpoint;
use NaokiTsuchiya\AgentBridge\Slack\SlackAppToken;
use NaokiTsuchiya\AgentBridge\Slack\SlackAppTokenFactory;
use NaokiTsuchiya\AgentBridge\Slack\SlackException;
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
 * environment turns into which endpoint is {@see SlackApiEndpointTest}'s.
 *
 * @internal
 */
final class SocketModeConnectorProviderTest extends TestCase
{
    /** A value shaped like an app-level token; no workspace is ever reached with it. */
    private const string TOKEN = SlackAppToken::PREFIX . 'shaped-like-one';

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
     * Nothing configured opens the connection against Slack itself, as it always has.
     *
     * @throws SlackException
     * @throws SocketModeException
     */
    #[Test]
    public function opensTheConnectionAgainstSlackItselfWhenTheEndpointIsNotConfigured(): void
    {
        putenv(SlackApiEndpoint::HOST_VARIABLE);
        putenv(SlackApiEndpoint::PORT_VARIABLE);

        self::assertSame([['slack.com', 443]], self::connectThrough());
    }

    /**
     * The point of the variables: the same handshake, aimed somewhere else.
     *
     * @throws SlackException
     * @throws SocketModeException
     */
    #[Test]
    public function opensTheConnectionWhereTheVariablesSay(): void
    {
        putenv(SlackApiEndpoint::HOST_VARIABLE . '=stub.internal');
        putenv(SlackApiEndpoint::PORT_VARIABLE . '=8443');

        self::assertSame([['stub.internal', 8443]], self::connectThrough());
    }

    /**
     * A port that is not one stops the start, which is what a person sees as exit 3.
     *
     * The app token is left unset on purpose: this provider is the one resolved while the process
     * starts, and the endpoint is read before the token so that the wrong setting is the one named.
     * Should that order ever change, the missing token's own refusal comes out of here instead.
     *
     * @throws SocketModeException
     */
    #[Test]
    public function refusesToBuildAConnectorWhenThePortIsNotAPort(): void
    {
        putenv(SlackApiEndpoint::PORT_VARIABLE . '=not-a-port');
        putenv(SlackAppTokenFactory::ENVIRONMENT_VARIABLE);

        $clients = new RecordingHttpClientFactory();

        try {
            new SocketModeConnectorProvider($clients)->get();
        } catch (SlackException $refusal) {
            self::assertStringContainsString(SlackApiEndpoint::PORT_VARIABLE, $refusal->getMessage());
            self::assertSame([], $clients->asked(), 'Nothing may be reached for.');

            return;
        }

        self::fail('The provider built a connector for a port that is not one.');
    }

    /**
     * Builds the connector the provider gives out and starts one handshake with it.
     *
     * @return list<array{string, int}> where that handshake asked for a connection
     *
     * @throws SlackException
     * @throws SocketModeException
     */
    private static function connectThrough(): array
    {
        putenv(SlackAppTokenFactory::ENVIRONMENT_VARIABLE . '=' . self::TOKEN);

        $clients = new RecordingHttpClientFactory();
        $connector = new SocketModeConnectorProvider($clients)->get();

        // The factory refuses, and the connector lets that out of `connect()`; by then the endpoint
        // it was going to open against has been recorded.
        try {
            $connector->connect();
        } catch (SocketModeException) {
            return $clients->asked();
        }

        self::fail('A connection was made without a workspace.');
    }

    /** @return list<string> every variable a case here may change */
    private static function variables(): array
    {
        return [
            SlackApiEndpoint::HOST_VARIABLE,
            SlackApiEndpoint::PORT_VARIABLE,
            SlackAppTokenFactory::ENVIRONMENT_VARIABLE,
        ];
    }
}
