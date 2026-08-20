<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Override;

use function array_shift;

/**
 * A connection that answers with whatever frames the test put in it, and remembers what it was told.
 *
 * Running out of frames is silence, which is what a Socket Mode connection looks like when it has
 * gone quiet; that is deliberate, and it is how the silence timeout is reached without waiting.
 *
 * @internal
 */
final class FakeSocketModeConnection implements SocketModeConnectionInterface
{
    /** @var list<float> the timeout of each receive, in order */
    public array $timeouts = [];

    /** @var list<string> every payload sent, in order */
    public array $sent = [];

    /** How many times the connection was released. */
    public int $closes = 0;

    /** Thrown by the next send instead of recording it, when the test set one. */
    public ?SocketModeException $sendFailure = null;

    /** @param list<string|SocketModeException> $frames handed out in order; an exception is thrown */
    public function __construct(
        private array $frames = [],
    ) {}

    /** @throws SocketModeException */
    #[Override]
    public function receive(float $timeout): ?string
    {
        $this->timeouts[] = $timeout;
        $next = array_shift($this->frames);

        if ($next instanceof SocketModeException) {
            throw $next;
        }

        return $next;
    }

    /** @throws SocketModeException */
    #[Override]
    public function send(string $payload): void
    {
        if ($this->sendFailure !== null) {
            throw $this->sendFailure;
        }

        $this->sent[] = $payload;
    }

    /** Counted rather than acted on: what matters is that the loop lets go of a dead connection. */
    #[Override]
    public function close(): void
    {
        $this->closes++;
    }
}
