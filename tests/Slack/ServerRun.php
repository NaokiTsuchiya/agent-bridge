<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

/**
 * What a finished run of the server left behind.
 *
 * Its parts are built per case rather than held as fields, because everything they touch — the
 * channel above all — belongs to the one coroutine the run happened in, and a field would outlive
 * it.
 *
 * @internal
 */
final class ServerRun
{
    /**
     * @param OneAttemptClient $connection what the server ran alongside the messages
     * @param RecordingLogger  $logger     what it said about the messages it could not answer
     */
    public function __construct(
        public OneAttemptClient $connection,
        public RecordingLogger $logger,
    ) {}
}
