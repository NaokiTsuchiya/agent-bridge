<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Pipeline;

use NaokiTsuchiya\AgentBridge\Chat\ChatEgress;
use NaokiTsuchiya\AgentBridge\Git\Git;
use NaokiTsuchiya\AgentBridge\Runner\AgentRunner;
use NaokiTsuchiya\AgentBridge\Thread\ThreadIdFactory;
use NaokiTsuchiya\AgentBridge\Worktree\WorktreeManager;
use Override;
use Ray\Di\AbstractModule;

/**
 * What the chain needs, wired to this test's own repository and front end.
 *
 * The shape matches {@see \NaokiTsuchiya\AgentBridge\Di\AppModule}: the same three types the
 * pipeline injects, bound to the same kinds of thing. What differs is that the repository, the
 * binary and the front end are this case's, which is also why the chain cannot be driven from the
 * compiled injector — those are runtime paths, and a compiled script may not carry one.
 */
final class PipelineModule extends AbstractModule
{
    /**
     * @param WorktreeManager $worktrees where this case's worktrees are cut from
     * @param AgentRunner     $runner    what answers a turn, shared with the caller so that it can
     *                                   be closed when the case is over
     * @param ChatEgress      $egress    where the turn goes out to
     */
    public function __construct(
        private WorktreeManager $worktrees,
        private AgentRunner $runner,
        private ChatEgress $egress,
    ) {
        parent::__construct();
    }

    /** @return WorktreeManager the manager of a repository, ready to be shared with a runner */
    public static function worktreesOf(string $repository): WorktreeManager
    {
        return new WorktreeManager($repository, new Git());
    }

    /** {@inheritDoc} */
    #[Override]
    protected function configure(): void
    {
        $this->bind(ThreadIdFactory::class);
        $this->bind(WorktreeManager::class)->toInstance($this->worktrees);
        $this->bind(AgentRunner::class)->toInstance($this->runner);
        $this->bind(ChatEgress::class)->toInstance($this->egress);
    }
}
