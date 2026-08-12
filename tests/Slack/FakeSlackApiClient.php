<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use NaokiTsuchiya\AgentBridge\Slack\SlackApiClient;
use NaokiTsuchiya\AgentBridge\Slack\SlackApiResult;
use NaokiTsuchiya\AgentBridge\Slack\SlackStream;
use Override;

use function array_slice;
use function array_values;

/**
 * A Web API that reaches no workspace and remembers every call made to it.
 *
 * This is what makes the whole egress testable without a token: the seam is the interface, so what
 * is exercised here is the real front end rather than a stand-in for it. Answers can be pinned
 * either for good ({@see refuse}) or one call at a time ({@see script}), which is how a rate limit
 * that clears on the retry is put to it.
 *
 * @internal
 */
final class FakeSlackApiClient implements SlackApiClient
{
    /** What this fake calls the stream it opens, standing in for the `ts` Slack answers with. */
    public const string STREAM_TS = '1700000009.000100';

    /** @var list<array{method: string, arguments: array<string, mixed>}> every call, in order */
    public array $calls = [];

    /** @var array<string, SlackApiResult> what a given method answers with, from now on */
    private array $answers = [];

    /** @var array<string, list<SlackApiResult>> what it answers with first, one per call */
    private array $scripted = [];

    /** Makes the named method answer the way Slack answers a call it will not carry out. */
    public function refuse(string $method, string $error): void
    {
        $this->answers[$method] = new SlackApiResult(ok: false, error: $error);
    }

    /**
     * Makes the named method answer with these, one per call, before it goes back to its usual one.
     *
     * @param SlackApiResult ...$results in the order the calls will receive them
     */
    public function script(string $method, SlackApiResult ...$results): void
    {
        $this->scripted[$method] = array_values($results);
    }

    /** {@inheritDoc} */
    #[Override]
    public function call(string $method, array $arguments): SlackApiResult
    {
        $this->calls[] = ['method' => $method, 'arguments' => $arguments];

        $scripted = $this->scripted[$method] ?? [];

        if ($scripted !== []) {
            $this->scripted[$method] = array_slice($scripted, offset: 1);

            return $scripted[0];
        }

        return $this->answers[$method] ?? self::usual($method);
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
     * @return list<array<string, mixed>> the arguments of every call to that method, in order
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

    /** @return SlackApiResult what a workspace that is not being asked anything unusual answers */
    private static function usual(string $method): SlackApiResult
    {
        // A stream that came back without a `ts` cannot be added to, so the one call that answers
        // with one has to answer with one here too, or nothing would ever be streamed.
        return $method === SlackStream::START
            ? new SlackApiResult(ok: true, ts: self::STREAM_TS)
            : new SlackApiResult(ok: true);
    }
}
