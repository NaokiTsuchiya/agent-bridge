<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Cli;

use NaokiTsuchiya\AgentBridge\Event\ClaudeCliEventParser;
use NaokiTsuchiya\AgentBridge\Runner\AgentRunner;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliCommand;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliSettings;
use NaokiTsuchiya\AgentBridge\Runner\LifecycleSettings;
use NaokiTsuchiya\AgentBridge\Runner\PersistentCliRunner;
use NaokiTsuchiya\AgentBridge\Runner\ProcessPool;
use NaokiTsuchiya\AgentBridge\Runner\TurnLocks;
use NaokiTsuchiya\AgentBridge\Runner\WorkingDirectoryResolver;
use NaokiTsuchiya\AgentBridge\Tests\Support\ClaudeBinary;
use Override;

/** How the answer streams out of the execution layer the application ships. */
final class CliChainOutputTest extends CliChainOutputTestCase
{
    /** {@inheritDoc} */
    #[Override]
    protected function runner(WorkingDirectoryResolver $directories): AgentRunner
    {
        $settings = new ClaudeCliSettings(binary: ClaudeBinary::fake(), closeGraceSeconds: 2.0);
        $limits = new LifecycleSettings();

        return new PersistentCliRunner(
            $directories,
            new ClaudeCliCommand($settings),
            new ClaudeCliEventParser(),
            new TurnLocks(),
            new ProcessPool($limits, $settings->closeGraceSeconds),
            $limits->turnSeconds,
        );
    }
}
