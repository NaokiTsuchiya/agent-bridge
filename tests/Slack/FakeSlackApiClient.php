<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use NaokiTsuchiya\AgentBridge\Slack\SlackApiClient;
use NaokiTsuchiya\AgentBridge\Slack\SlackApiResult;
use Override;

/**
 * A Web API that reaches no workspace and remembers every call made to it.
 *
 * This is what makes the whole egress testable without a token: the seam is the interface, so what
 * is exercised here is the real front end rather than a stand-in for it.
 *
 * @internal
 */
final class FakeSlackApiClient implements SlackApiClient
{
    /** @var list<array{method: string, arguments: array<string, string>}> every call, in order */
    public array $calls = [];

    /** @var array<string, SlackApiResult> what a given method answers with, when the test says so */
    private array $answers = [];

    /** Makes the named method answer the way Slack answers a call it will not carry out. */
    public function refuse(string $method, string $error): void
    {
        $this->answers[$method] = new SlackApiResult(ok: false, error: $error);
    }

    /** {@inheritDoc} */
    #[Override]
    public function call(string $method, array $arguments): SlackApiResult
    {
        $this->calls[] = ['method' => $method, 'arguments' => $arguments];

        return $this->answers[$method] ?? new SlackApiResult(ok: true);
    }

    /** @return list<string> the name of every method called, in order */
    public function methods(): array
    {
        $methods = [];
        foreach ($this->calls as $call) {
            $methods[] = $call['method'];
        }

        return $methods;
    }

    /**
     * @return list<array<string, string>> the arguments of every call to that method, in order
     */
    public function argumentsOf(string $method): array
    {
        $arguments = [];
        foreach ($this->calls as $call) {
            if ($call['method'] !== $method) {
                continue;
            }

            $arguments[] = $call['arguments'];
        }

        return $arguments;
    }
}
