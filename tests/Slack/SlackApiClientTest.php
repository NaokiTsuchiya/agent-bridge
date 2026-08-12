<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Slack\SlackApiClient;
use NaokiTsuchiya\AgentBridge\Slack\SlackApiResponse;
use NaokiTsuchiya\AgentBridge\Slack\SlackAppToken;
use NaokiTsuchiya\AgentBridge\Slack\SlackBotToken;
use NaokiTsuchiya\AgentBridge\Slack\SwooleSlackApiClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use Swoole\Coroutine\Http\Client;

use function file_get_contents;
use function str_contains;

/**
 * The seam the Web API is reached through, and what the production side of it is made of.
 *
 * The seam itself is what every other case in this directory relies on: a front end that reached
 * for a client of its own could not be exercised without a workspace. The two source-level cases
 * are about the parts of the production client that cannot be run here at all — which client class
 * it opens, and that it does not read a response body itself.
 */
final class SlackApiClientTest extends TestCase
{
    /**
     * The production client is an implementation of the seam, not something beside it.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function isAnImplementationOfTheSeam(): void
    {
        self::assertTrue(new ReflectionClass(SwooleSlackApiClient::class)->implementsInterface(SlackApiClient::class));
    }

    /** A test can put its own implementation in that place, which is what makes the egress testable. */
    #[Test]
    public function acceptsAStandInFromATest(): void
    {
        $fake = new FakeSlackApiClient();

        self::assertInstanceOf(SlackApiClient::class, $fake);
        self::assertTrue($fake->call('chat.postMessage', ['channel' => 'C0CHANNEL'])->ok);
        self::assertSame(['chat.postMessage'], $fake->methods());
    }

    /**
     * The production client talks over Swoole's coroutine HTTP client and no other.
     *
     * A synchronous client would hold the whole event loop for the length of every call — the
     * WebSocket, every thread's turn, and the acknowledgements Slack gives three seconds for.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function opensACoroutineHttpClient(): void
    {
        self::assertTrue(str_contains(self::sourceOf(SwooleSlackApiClient::class), 'use ' . Client::class . ';'));
    }

    /**
     * The production client makes no judgement about a response body.
     *
     * What a body means is {@see SlackApiResponse}'s, where it can be exercised without a
     * workspace; a client that decoded the body itself would put that judgement out of reach.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function leavesTheBodyToSomethingThatCanBeTested(): void
    {
        $source = self::sourceOf(SwooleSlackApiClient::class);

        self::assertTrue(str_contains($source, 'SlackApiResponse::of('));
        self::assertFalse(str_contains($source, 'json_decode'), 'The client reads a body itself.');
    }

    /**
     * A bot token exists only if it could be one.
     *
     * @throws InvalidArgumentException never; the value below is shaped like one
     */
    #[Test]
    public function acceptsABotToken(): void
    {
        $shaped = SlackBotToken::PREFIX . 'shaped-like-one-but-reaching-nothing';

        self::assertSame($shaped, new SlackBotToken($shaped)->value);
    }

    /**
     * @param string $value what somebody put in the environment
     *
     * @throws InvalidArgumentException which is what the case is about
     */
    #[DataProvider('unusableTokens')]
    #[Test]
    public function refusesAnythingElse(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SlackBotToken($value);
    }

    /** @return iterable<string, array{string}> */
    public static function unusableTokens(): iterable
    {
        yield 'nothing' => [''];
        yield 'blank' => ['   '];
        yield 'an app-level token' => [SlackAppToken::PREFIX . 'this-one-opens-a-socket'];
        yield 'something else entirely' => ['hunter2'];
    }

    /** The reason the two tokens are separate types is that neither can be put where the other goes. */
    #[Test]
    public function keepsTheValueOutOfTheRefusal(): void
    {
        $refusal = null;

        try {
            new SlackBotToken('xoxp-a-user-token-nobody-should-see');
        } catch (InvalidArgumentException $exception) {
            $refusal = $exception->getMessage();
        }

        self::assertIsString($refusal);
        self::assertFalse(str_contains($refusal, 'nobody-should-see'));
    }

    /**
     * The client is built out of a factory rather than a client, which is what leaves TLS outside it.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function takesItsClientsFromTheFactory(): void
    {
        $parameters = new ReflectionClass(SwooleSlackApiClient::class)->getConstructor()?->getParameters() ?? [];
        $names = [];
        foreach ($parameters as $parameter) {
            $names[] = $parameter->getName();
        }

        self::assertSame(['token', 'clients', 'apiHost', 'apiPort'], $names);
    }

    /**
     * @param class-string $class
     *
     * @return string the file that class is written in
     *
     * @throws ReflectionException
     */
    private static function sourceOf(string $class): string
    {
        $file = new ReflectionClass($class)->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }
}
