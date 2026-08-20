<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

/**
 * What a finished run of the client left behind, gathered inside the coroutine it ran in.
 *
 * The channel cannot be read outside a coroutine at all, and an assertion that fails inside one
 * takes the process down with it, so everything is collected first and asserted afterwards.
 *
 * @internal
 */
final class RunOutcome
{
    /**
     * @param list<mixed> $handedOn      everything the channel held when the run ended, oldest first
     * @param int         $channelLength how many items were on the channel before it was drained
     */
    public function __construct(
        public FakeSocketModeConnector $connector,
        public RecordingSleeper $sleeper,
        public RecordingLogger $logger,
        public array $handedOn,
        public int $channelLength,
    ) {}
}
