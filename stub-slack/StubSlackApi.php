<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\StubSlack;

use function array_shift;

/**
 * What a `StubSlackServer` answers for the five Web API methods this repository sends, and every
 * call it was actually asked to answer.
 *
 * The production side names `assistant.threads.setStatus` as a `private` constant of
 * `Slack\SlackEgress`, out of reach from here, so the five method names are this class's own —
 * this stub has exactly one thing to say about each of them, independent of what the production
 * class happens to call itself.
 *
 * Mirrors the shape of `Tests\Slack\FakeSlackApiClient` (record every call, answer from a
 * one-shot script before falling back to a default) on purpose, but cannot reuse it: that class
 * implements `Slack\SlackApiClient` directly and is driven in-process with no HTTP involved, while
 * this one sits on the far side of a real socket, inside `StubSlackServer`'s request handler.
 *
 * @internal
 */
final class StubSlackApi
{
    /** Opens or continues the answer as one message. */
    public const string POST_MESSAGE = 'chat.postMessage';

    /** Opens a streamed answer. */
    public const string START = 'chat.startStream';

    /** Adds to a streamed answer. */
    public const string APPEND = 'chat.appendStream';

    /** Ends a streamed answer. */
    public const string STOP = 'chat.stopStream';

    /** Shows a status next to a thread. */
    public const string SET_STATUS = 'assistant.threads.setStatus';

    /** Every method this stub answers a `/api/{method}` request for. */
    public const array METHODS = [self::POST_MESSAGE, self::START, self::APPEND, self::STOP, self::SET_STATUS];

    /** The `ts` a canned `chat.postMessage`/`chat.startStream` answer carries back. */
    public const string TS = '1700000000.000100';

    /** @var list<array{method: string, arguments: array<array-key, mixed>}> every call, in order */
    public array $calls = [];

    /** @var array<string, list<StubApiAnswer>> what a method answers with next, consumed one per call */
    private array $scripted = [];

    /**
     * Makes the named method answer with these, one per call, before falling back to the default.
     *
     * @param StubApiAnswer ...$answers in the order the calls will receive them
     */
    public function script(string $method, StubApiAnswer ...$answers): void
    {
        $this->scripted[$method] = [...$answers];
    }

    /**
     * Records the call, then answers it — scripted first, the usual answer once the script runs out.
     *
     * @param array<array-key, mixed> $arguments the call's decoded body
     */
    public function answer(string $method, array $arguments): StubApiAnswer
    {
        $this->calls[] = ['method' => $method, 'arguments' => $arguments];

        $queued = $this->scripted[$method] ?? [];

        if ($queued === []) {
            return self::defaultAnswer($method);
        }

        $next = array_shift($queued);
        $this->scripted[$method] = $queued;

        return $next;
    }

    /**
     * What a call this stub was never told to answer specially gets.
     *
     * `chat.postMessage` and `chat.startStream` are the two calls a caller reads a `ts` back from
     * (`SlackReply`'s own message, and the message a stream is appended to and stopped through), so
     * only those two carry one in the default answer.
     */
    private static function defaultAnswer(string $method): StubApiAnswer
    {
        return match ($method) {
            self::POST_MESSAGE, self::START => new StubApiAnswer(body: ['ok' => true, 'ts' => self::TS]),
            default => new StubApiAnswer(),
        };
    }
}
