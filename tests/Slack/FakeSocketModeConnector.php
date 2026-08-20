<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Closure;
use Override;

use function array_shift;

/**
 * Hands out prepared connections, counting the attempts, and lets the test act on each one.
 *
 * The hook is how a run loop that never ends on its own is ended: a test stops the client from
 * inside the attempt it wants to be the last, which needs no timers and no real waiting.
 *
 * @internal
 */
final class FakeSocketModeConnector implements SocketModeConnectorInterface
{
    /** How many times a connection was asked for. */
    public int $attempts = 0;

    /**
     * @param list<FakeSocketModeConnection|SocketModeException> $connections handed out in order; an
     *                                                                       exception is thrown as a
     *                                                                       failed attempt
     * @param Closure(int):void|null                             $onConnect  run before the attempt is
     *                                                                       answered, with the attempt
     *                                                                       number
     */
    public function __construct(
        private array $connections,
        private ?Closure $onConnect = null,
    ) {}

    /** @throws SocketModeException */
    #[Override]
    public function connect(): SocketModeConnectionInterface
    {
        $this->attempts++;

        if ($this->onConnect !== null) {
            ($this->onConnect)($this->attempts);
        }

        $next = array_shift($this->connections);

        if ($next instanceof SocketModeException) {
            throw $next;
        }

        // Past the prepared ones, every connection is silent: the test has said what it cares
        // about, and an idle connection cannot make the loop do anything else.
        return $next ?? new FakeSocketModeConnection();
    }
}
