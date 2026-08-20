<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Worktree;

use NaokiTsuchiya\AgentBridge\Git\GitInterface;
use Override;

use function implode;

/**
 * Runs git for real while keeping the argument list of every call.
 *
 * @internal
 */
final class RecordingGit implements GitInterface
{
    /** @var list<string> the arguments of each call, joined by spaces, in the order they were made */
    public array $commands = [];

    /** Whatever the manager is meant to talk to; this one only listens in. */
    public function __construct(
        private GitInterface $git,
    ) {}

    /** Records first, so that a call is remembered even when git itself fails. */
    #[Override]
    public function run(string $repository, array $arguments): array
    {
        $this->commands[] = implode(' ', $arguments);

        return $this->git->run($repository, $arguments);
    }
}
