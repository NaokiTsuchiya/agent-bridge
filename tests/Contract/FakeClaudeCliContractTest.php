<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Contract;

use NaokiTsuchiya\AgentBridge\Tests\Support\TempDir;
use Override;

use function dirname;

/**
 * The contract, run against the fake — the side that runs in CI.
 *
 * Its twin against the real binary is in tests/Integration. Nothing is asserted here that is not
 * asserted there; this class only says which binary to start and where it may keep its state.
 */
final class FakeClaudeCliContractTest extends ClaudeCliContractTestCase
{
    /** Where this run keeps its sessions and recordings, thrown away with the test. */
    private string $home = '';

    /** A state root of this test alone, on top of the working directory the case makes. */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->home = TempDir::make('fake-home');
    }

    /** Removes the state root after the case has stopped the processes that were writing to it. */
    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        TempDir::remove($this->home);
    }

    /** @return list<string> */
    #[Override]
    protected function binary(): array
    {
        return [dirname(__DIR__, levels: 2) . '/tests/Fake/bin/claude'];
    }

    /** @return array<string, string> */
    #[Override]
    protected function environment(): array
    {
        return ['FAKE_CLAUDE_HOME' => $this->home];
    }
}
