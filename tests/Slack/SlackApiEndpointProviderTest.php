<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function getenv;
use function putenv;

/**
 * Which environment turns into which endpoint.
 *
 * The port is the only thing here that can be wrong, so the cases are grouped that way: the shapes
 * `getenv` answers with on the way in, and the shapes a value can take that is not a port number.
 * The refusals are the ones a person meets at start-up, so each of them asks the message to name
 * the variable and quote what was in it — a refusal that says neither leaves nothing to act on.
 *
 * @internal
 *
 * @mago-expect lint:too-many-methods
 */
final class SlackApiEndpointProviderTest extends TestCase
{
    /** What the host variable held before the case ran, put back afterwards. */
    private string|false $host = false;

    /** What the port variable held before the case ran, put back afterwards. */
    private string|false $port = false;

    /** Both variables are process-wide, so a case that changed one must hand it back. */
    #[Override]
    protected function setUp(): void
    {
        $this->host = getenv(SlackApiEndpointProvider::HOST_VARIABLE);
        $this->port = getenv(SlackApiEndpointProvider::PORT_VARIABLE);
    }

    /** Restores whatever was there, including its absence. */
    #[Override]
    protected function tearDown(): void
    {
        self::restore(SlackApiEndpointProvider::HOST_VARIABLE, $this->host);
        self::restore(SlackApiEndpointProvider::PORT_VARIABLE, $this->port);
    }

    /**
     * The deployment nobody configured, which is every deployment there has been until now.
     *
     * @throws SlackException
     */
    #[Test]
    public function reachesSlackItselfWhenNeitherVariableIsSet(): void
    {
        putenv(SlackApiEndpointProvider::HOST_VARIABLE);
        putenv(SlackApiEndpointProvider::PORT_VARIABLE);

        $endpoint = new SlackApiEndpointProvider()->get();

        self::assertSame('slack.com', $endpoint->host);
        self::assertSame(443, $endpoint->port);
    }

    /**
     * An empty value is what an unset-looking `FOO=` in a unit file or compose file produces.
     *
     * @throws SlackException
     */
    #[Test]
    public function reachesSlackItselfWhenBothVariablesAreEmpty(): void
    {
        putenv(SlackApiEndpointProvider::HOST_VARIABLE . '=');
        putenv(SlackApiEndpointProvider::PORT_VARIABLE . '=');

        $endpoint = new SlackApiEndpointProvider()->get();

        self::assertSame('slack.com', $endpoint->host);
        self::assertSame(443, $endpoint->port);
    }

    /**
     * The case the variables exist for: a stub somewhere else, on a port of its own.
     *
     * @throws SlackException
     */
    #[Test]
    public function readsBothVariablesWhenTheyAreSet(): void
    {
        putenv(SlackApiEndpointProvider::HOST_VARIABLE . '=stub.internal');
        putenv(SlackApiEndpointProvider::PORT_VARIABLE . '=8443');

        $endpoint = new SlackApiEndpointProvider()->get();

        self::assertSame('stub.internal', $endpoint->host);
        self::assertSame(8443, $endpoint->port);
    }

    /**
     * The edges of the range, and the padding a shell here-doc leaves behind.
     *
     * @param string $value what the variable holds
     * @param int    $port  the port that has to come out of it
     *
     * @throws SlackException
     */
    #[DataProvider('portsThatAreAccepted')]
    #[Test]
    public function acceptsEveryPortInTheRange(string $value, int $port): void
    {
        putenv(SlackApiEndpointProvider::PORT_VARIABLE . "={$value}");

        self::assertSame($port, new SlackApiEndpointProvider()->get()->port);
    }

    /**
     * A host of nothing but whitespace is somebody's value, not an absent one.
     *
     * Treating it as absent would aim the process at `slack.com` while the deployment said
     * otherwise, and say nothing about having done so; a host that cannot be resolved fails where
     * it can be seen.
     *
     * @throws SlackException
     */
    #[Test]
    public function keepsAHostThatIsNothingButWhitespace(): void
    {
        putenv(SlackApiEndpointProvider::HOST_VARIABLE . '=   ');
        putenv(SlackApiEndpointProvider::PORT_VARIABLE);

        self::assertSame('   ', new SlackApiEndpointProvider()->get()->host);
    }

    /**
     * Everything that is not a port number is refused by name, with the value quoted back.
     *
     * @param string $value what the variable holds
     */
    #[DataProvider('valuesThatAreNotPorts')]
    #[Test]
    public function refusesAValueThatIsNotAPort(string $value): void
    {
        putenv(SlackApiEndpointProvider::PORT_VARIABLE . "={$value}");

        try {
            new SlackApiEndpointProvider()->get();
        } catch (SlackException $refusal) {
            self::assertStringContainsString(SlackApiEndpointProvider::PORT_VARIABLE, $refusal->getMessage());
            self::assertStringContainsString("\"{$value}\"", $refusal->getMessage());

            return;
        }

        self::fail("A port of \"{$value}\" was accepted.");
    }

    /** @return iterable<string, array{string, int}> */
    public static function portsThatAreAccepted(): iterable
    {
        yield 'the lowest port there is' => ['1', 1];
        yield 'the highest port there is' => ['65535', 65_535];
        yield 'padded with spaces' => [' 443 ', 443];
    }

    /** @return iterable<string, array{string}> */
    public static function valuesThatAreNotPorts(): iterable
    {
        yield 'no digits at all' => ['abc'];
        yield 'digits with something after them' => ['8443abc'];
        yield 'a decimal that happens to be whole' => ['443.0'];
        yield 'a leading zero' => ['0443'];
        yield 'the port that asks the OS to pick' => ['0'];
        yield 'one above the highest' => ['65536'];
        yield 'nothing but whitespace' => ['   '];
    }

    /**
     * @param string       $variable which one to put back
     * @param string|false $value    what it held, false meaning it was not set
     */
    private static function restore(string $variable, string|false $value): void
    {
        if ($value === false) {
            putenv($variable);

            return;
        }

        putenv("{$variable}={$value}");
    }
}
