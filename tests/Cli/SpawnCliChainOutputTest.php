<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Cli;

use NaokiTsuchiya\AgentBridge\Event\ClaudeCliEventParser;
use NaokiTsuchiya\AgentBridge\Runner\AgentRunner;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliCommand;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliSettings;
use NaokiTsuchiya\AgentBridge\Runner\LifecycleSettings;
use NaokiTsuchiya\AgentBridge\Runner\SpawnCliRunner;
use NaokiTsuchiya\AgentBridge\Runner\TurnLocks;
use NaokiTsuchiya\AgentBridge\Runner\WorkingDirectoryResolver;
use NaokiTsuchiya\AgentBridge\Tests\Support\ClaudeBinary;
use Override;

/** The same promise about streaming, kept by a runner that starts a process per turn. */
final class SpawnCliChainOutputTest extends CliChainOutputTestCase
{
    /** {@inheritDoc} */
    #[Override]
    protected function runner(WorkingDirectoryResolver $directories): AgentRunner
    {
        return new SpawnCliRunner(
            $directories,
            new ClaudeCliCommand(new ClaudeCliSettings(binary: ClaudeBinary::fake())),
            new ClaudeCliEventParser(),
            new TurnLocks(),
            new LifecycleSettings()->turnSeconds,
        );
    }
}
