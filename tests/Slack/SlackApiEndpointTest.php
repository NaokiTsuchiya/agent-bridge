<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What makes a host and a port an endpoint something can be aimed at.
 *
 * Where an endpoint is configured is {@see SlackApiEndpointProviderTest}'s; what is asked here is
 * only which pairs exist at all. A port outside the range would otherwise travel as far as a
 * connection attempt, where nothing left can tell it from a workspace that is down.
 *
 * @internal
 */
final class SlackApiEndpointTest extends TestCase
{
    /**
     * The pair is handed on as it was given: no name is resolved, no port is guessed.
     *
     * @throws InvalidArgumentException
     */
    #[Test]
    public function holdsTheHostAndPortItWasGiven(): void
    {
        $endpoint = new SlackApiEndpoint('stub.internal', 8443);

        self::assertSame('stub.internal', $endpoint->host);
        self::assertSame(8443, $endpoint->port);
    }

    /**
     * Both edges of the range are ports, and a deployment may sit on either.
     *
     * @param int $port the one to be accepted
     *
     * @throws InvalidArgumentException
     */
    #[DataProvider('portsInTheRange')]
    #[Test]
    public function acceptsEveryPortInTheRange(int $port): void
    {
        self::assertSame($port, new SlackApiEndpoint('stub.internal', $port)->port);
    }

    /**
     * A number that is not a port is refused where the pair is made, with the number said back.
     *
     * @param int $port the one to be refused
     */
    #[DataProvider('portsOutsideTheRange')]
    #[Test]
    public function refusesANumberThatIsNotAPort(int $port): void
    {
        try {
            new SlackApiEndpoint('stub.internal', $port);
        } catch (InvalidArgumentException $refusal) {
            self::assertStringContainsString((string) $port, $refusal->getMessage());

            return;
        }

        self::fail("A port of {$port} was accepted.");
    }

    /** @return iterable<string, array{int}> */
    public static function portsInTheRange(): iterable
    {
        yield 'the lowest port there is' => [SlackApiEndpoint::LOWEST_PORT];
        yield 'the highest port there is' => [SlackApiEndpoint::HIGHEST_PORT];
    }

    /** @return iterable<string, array{int}> */
    public static function portsOutsideTheRange(): iterable
    {
        yield 'the port that asks the OS to pick' => [SlackApiEndpoint::LOWEST_PORT - 1];
        yield 'one above the highest' => [SlackApiEndpoint::HIGHEST_PORT + 1];
        yield 'a negative number' => [-1];
    }
}
