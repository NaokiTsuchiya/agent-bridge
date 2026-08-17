<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use Be\Framework\Module\BeModule;
use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\AgentBridge;
use NaokiTsuchiya\AgentBridge\Di\SlackModule;
use NaokiTsuchiya\AgentBridge\Slack\RetryingSlackApiClient;
use NaokiTsuchiya\AgentBridge\Slack\SlackApiClient;
use NaokiTsuchiya\AgentBridge\Slack\SlackApiClientProvider;
use NaokiTsuchiya\AgentBridge\Slack\SlackApiEndpoint;
use NaokiTsuchiya\AgentBridge\Slack\SlackBotToken;
use NaokiTsuchiya\AgentBridge\Slack\SlackException;
use NaokiTsuchiya\AgentBridge\Slack\StreamingSettings;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function getenv;
use function putenv;

/**
 * Where the Web API client the provider builds actually sends its calls, and what it will not build.
 *
 * The client is asked to make one call, because that is when it opens a connection: the factory it
 * is given records the host and port instead of connecting, so a case can see the endpoint without
 * a socket. {@see SlackApiEndpointProviderTest} covers which environment turns into which endpoint;
 * what is asked here is whether this provider hands the endpoint it was given on, and what it says
 * when the environment holds no token it could call anything with. Every refusal is met by a person
 * starting a process, so each case asks the message to name the variable — and the refusals are
 * different actions: one variable to export, or one value to correct.
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

    /**
     * A variable nobody exported is the first thing a new deployment gets wrong, and it is a
     * different mistake from a variable holding the wrong thing: nothing was refused, so there is
     * nothing to have refused it. The transport is not reached either — a credential is read before
     * anything is built out of it.
     *
     * @throws InvalidArgumentException never; the endpoint the refusal is asked for is a usable one
     */
    #[Test]
    public function namesTheVariableWhenNobodyExportedIt(): void
    {
        putenv(SlackApiClientProvider::ENVIRONMENT_VARIABLE);

        $clients = new RecordingHttpClientFactory();
        $refusal = self::refusal($clients);

        self::assertStringContainsString(SlackApiClientProvider::ENVIRONMENT_VARIABLE, $refusal->getMessage());
        self::assertStringContainsString('is not set', $refusal->getMessage());
        self::assertStringContainsString('docs/slack-adapter.md', $refusal->getMessage());
        self::assertNull($refusal->getPrevious());
        self::assertSame([], $clients->asked());
    }

    /**
     * A value that could not be a bot token is refused as the environment's fault rather than the
     * caller's, the reason it could not be used is carried along instead of being restated, and the
     * value itself stays out of a message that is read in logs.
     *
     * @param string $value what a deployment might have exported
     *
     * @throws InvalidArgumentException never; the endpoint the refusal is asked for is a usable one
     */
    #[DataProvider('valuesThatAreNotBotTokens')]
    #[Test]
    public function namesTheVariableWhenItHoldsSomethingElse(string $value): void
    {
        putenv(SlackApiClientProvider::ENVIRONMENT_VARIABLE . "={$value}");

        $clients = new RecordingHttpClientFactory();
        $refusal = self::refusal($clients);

        self::assertStringStartsWith(
            SlackApiClientProvider::ENVIRONMENT_VARIABLE . ' does not hold a usable token.',
            $refusal->getMessage(),
        );
        self::assertInstanceOf(InvalidArgumentException::class, $refusal->getPrevious());
        self::assertStringNotContainsString($value, $refusal->getMessage());
        self::assertSame([], $clients->asked());
    }

    /** @return iterable<string, array{string}> */
    public static function valuesThatAreNotBotTokens(): iterable
    {
        yield 'spaces' => ['   '];
        yield 'a newline' => ["\n"];
        yield 'an app-level token' => ['xapp-1-A01234567-x'];
        yield 'the prefix in capitals' => ['XOXB-1-A01234567'];
        yield 'a leading space' => [' xoxb-1-A01234567'];
        yield 'the prefix in the middle' => ['bearer xoxb-1-A01234567'];
    }

    /**
     * An empty value is what an unset-looking `FOO=` in a unit file leaves behind, and it is refused
     * as a value rather than as an absence.
     *
     * Kept apart from the cases above because a message is asked there not to repeat what it
     * refused, and every message contains the empty string.
     *
     * @throws InvalidArgumentException never; the endpoint the refusal is asked for is a usable one
     */
    #[Test]
    public function treatsAnEmptyValueAsAValueRatherThanAnAbsence(): void
    {
        putenv(SlackApiClientProvider::ENVIRONMENT_VARIABLE . '=');

        $refusal = self::refusal(new RecordingHttpClientFactory());

        self::assertStringStartsWith(
            SlackApiClientProvider::ENVIRONMENT_VARIABLE . ' does not hold a usable token.',
            $refusal->getMessage(),
        );
    }

    /**
     * What a Slack process resolves is the client that waits a rate limit out, not the transport it
     * waits on behalf of.
     *
     * Taken from the real module rather than from a direct call, because the wrapping is the
     * provider's own decision: a binding that reached the transport instead would answer every call
     * the same way until Slack asked it to slow down.
     */
    #[Test]
    public function resolvesTheClientThatWaitsOutARateLimit(): void
    {
        putenv(SlackApiClientProvider::ENVIRONMENT_VARIABLE . '=' . self::TOKEN);
        $injector = new Injector(new BeModule(AgentBridge::SEMANTIC_NAMESPACE, new SlackModule()));

        self::assertInstanceOf(RetryingSlackApiClient::class, $injector->getInstance(SlackApiClient::class));
    }

    /**
     * @param RecordingHttpClientFactory $clients what a client would have been built out of
     *
     * @return SlackException what the provider refused the current environment with
     *
     * @throws InvalidArgumentException never; the endpoint below is a usable one
     */
    private static function refusal(RecordingHttpClientFactory $clients): SlackException
    {
        try {
            new SlackApiClientProvider(
                $clients,
                new SlackApiEndpoint('stub.internal', 8443),
                new RecordingSleeper(),
                new StreamingSettings(),
            )->get();
        } catch (SlackException $refusal) {
            return $refusal;
        }

        self::fail('A client was built out of an environment that holds no usable token.');
    }
}
