<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Runner;

use NaokiTsuchiya\AgentBridge\Runner\WorkingDirectoryResolver;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;

/**
 * The stand-in for issue #11's worktree lookup: one directory, whoever asks.
 *
 * It records what it was asked about, which is the only way a test can tell that the runner went
 * through the collaborator rather than working the directory out for itself.
 */
final class FixedWorkingDirectory implements WorkingDirectoryResolver
{
    /** @var list<string> the thread ids this was asked about, in order */
    public array $asked = [];

    /** @param string $path handed back to every caller; give it a resolved path */
    public function __construct(
        private string $path,
    ) {}

    /** @return string the one path this was built with, whichever thread is asked about */
    #[Override]
    public function resolve(ThreadId $thread): string
    {
        $this->asked[] = $thread->value;

        return $this->path;
    }
}
