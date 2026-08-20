<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use NaokiTsuchiya\AgentBridge\Thread\ThreadId;

/**
 * Where a thread's process starts, and with what arguments — the two things
 * {@see PersistentCliRunner::launch()} needs and nothing it keeps between turns.
 *
 * @api
 */
final readonly class ProcessRecipe
{
    /**
     * @param WorkingDirectoryResolver $directories where each thread's process is started
     * @param ClaudeCliCommand         $command     how the binary is asked to run
     */
    public function __construct(
        private WorkingDirectoryResolver $directories,
        private ClaudeCliCommand $command,
    ) {}

    /** @return AgentProcess|null null when the binary could not be started */
    public function start(ThreadId $thread, HistoryStart $start): ?AgentProcess
    {
        $command = $this->command->arguments($thread, $start);
        $cwd = $this->directories->resolve($thread);

        return AgentProcess::start($command, $cwd, $start);
    }
}
