<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use NaokiTsuchiya\AgentBridge\Slack\HttpClientFactoryInterface;
use NaokiTsuchiya\AgentBridge\Slack\SocketModeException;
use Override;
use Swoole\Coroutine\Http\Client;

/**
 * Keeps what a client was aimed at instead of making one, which is what makes the endpoint visible.
 *
 * A real `Swoole\Coroutine\Http\Client` would need a coroutine and a socket, and neither says
 * anything about where the call was going. Refusing is within the contract of the seam
 * ({@see HttpClientFactoryInterface::create} may throw), and both callers answer that refusal
 * without a second attempt: the Web API client turns it into a failed result, the connector lets it
 * out of `connect()`. So one call is recorded per attempt.
 *
 * @internal
 */
final class RecordingHttpClientFactory implements HttpClientFactoryInterface
{
    /** @var list<array{string, int}> the host and port of every attempt, in order */
    private array $attempts = [];

    /** @return list<array{string, int}> where a client was asked for, in order */
    public function asked(): array
    {
        return $this->attempts;
    }

    /** @throws SocketModeException always, after the attempt has been recorded */
    #[Override]
    public function create(string $host, int $port): Client
    {
        $this->attempts[] = [$host, $port];

        throw new SocketModeException("This factory makes no clients; it was asked for {$host}:{$port}.");
    }
}
