<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Pipeline;

use NaokiTsuchiya\AgentBridge\Event\AgentEvent;
use NaokiTsuchiya\AgentBridge\Runner\AgentRunner;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;

/**
 * An execution layer that answers with a fixed list of events, and remembers what it was let go of.
 *
 * The fake CLI covers everything the real one can produce, which is why the rest of the suite runs
 * against it. This exists for the one event nothing produces yet — {@see \NaokiTsuchiya\AgentBridge\Event\ToolCompleted}
 * — so that how the pipeline treats it is pinned before a producer appears, and for the callers
 * whose job is to let a thread go rather than to answer it.
 */
final class StubAgentRunner implements AgentRunner
{
    /** @var list<string> the threads this was asked to give up, in order */
    public array $closed = [];

    /** @param list<AgentEvent> $events what every turn on every thread answers with */
    public function __construct(
        private array $events,
    ) {}

    /** @return iterable<AgentEvent> */
    #[Override]
    public function send(ThreadId $thread, string $prompt): iterable
    {
        return $this->events;
    }

    /** Nothing is held, so this only writes down that it was asked. */
    #[Override]
    public function close(ThreadId $thread): void
    {
        $this->closed[] = $thread->value;
    }
}
