<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Worktree;

use NaokiTsuchiya\AgentBridge\Git\GitInterface;
use Override;

use function implode;

/**
 * A git that starts nothing and always succeeds, keeping the argument list of every call.
 *
 * {@see RecordingGit} runs the real binary, so it needs a repository on disk to run it in. This one
 * is for the cases where there deliberately is none — where what is under test is the path handling
 * that happens before git is reached at all.
 *
 * @internal
 */
final class StubGit implements GitInterface
{
    /** @var list<string> the arguments of each call, joined by spaces, in the order they were made */
    public array $commands = [];

    /** {@inheritDoc} */
    #[Override]
    public function run(string $repository, array $arguments): array
    {
        $this->commands[] = implode(' ', $arguments);

        return [0, ''];
    }
}
