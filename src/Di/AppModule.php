<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Di;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\Module\ResourceObjectModule;
use BEAR\Resource\ResourceObject;
use NaokiTsuchiya\AgentBridge\AgentBridge;
use NaokiTsuchiya\AgentBridge\Chat\ChatEgress;
use NaokiTsuchiya\AgentBridge\Cli\Conversation;
use NaokiTsuchiya\AgentBridge\Cli\StandardStreamsProvider;
use NaokiTsuchiya\AgentBridge\Event\ClaudeCliEventParser;
use NaokiTsuchiya\AgentBridge\Git\Git;
use NaokiTsuchiya\AgentBridge\Git\GitInterface;
use NaokiTsuchiya\AgentBridge\Resource\App\Health;
use NaokiTsuchiya\AgentBridge\Runner\AgentRunner;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliCommand;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliSettings;
use NaokiTsuchiya\AgentBridge\Runner\CloseGraceSeconds;
use NaokiTsuchiya\AgentBridge\Runner\LifecycleSettings;
use NaokiTsuchiya\AgentBridge\Runner\PersistentCliRunner;
use NaokiTsuchiya\AgentBridge\Runner\ProcessPool;
use NaokiTsuchiya\AgentBridge\Runner\ProcessRecipe;
use NaokiTsuchiya\AgentBridge\Runner\TurnLocks;
use NaokiTsuchiya\AgentBridge\Runner\TurnSeconds;
use NaokiTsuchiya\AgentBridge\Runner\WorkingDirectoryResolver;
use NaokiTsuchiya\AgentBridge\Runner\WorktreeWorkingDirectory;
use NaokiTsuchiya\AgentBridge\Thread\ThreadIdFactory;
use NaokiTsuchiya\AgentBridge\Worktree\BaseRepository;
use NaokiTsuchiya\AgentBridge\Worktree\WorktreeManager;
use Override;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;

/**
 * Everything this application binds, independent of which context installs it.
 *
 * @api
 */
final class AppModule extends AbstractModule
{
    /**
     * Every resource class that may be reached over a URI.
     *
     * A compiled injector can only build what was bound when it was compiled, and the adapter that
     * turns `app://self/health` into a class name asks the injector for a class nobody bound
     * otherwise. The list is written out rather than scanned: a scan would have to be handed the
     * source directory, which is exactly the kind of build-machine path that must not reach a
     * compiled script.
     *
     * @var list<class-string<ResourceObject>>
     */
    private const array RESOURCES = [Health::class];

    /** {@inheritDoc} */
    #[Override]
    protected function configure(): void
    {
        $this->bind('')->annotatedWith(BaseRepository::class)->toProvider(BaseRepositoryProvider::class);
        $this->bind(GitInterface::class)->to(Git::class);
        $this->bind(WorktreeManager::class);
        $this->bind(WorkingDirectoryResolver::class)->to(WorktreeWorkingDirectory::class);
        // Asked for while a turn is being answered, so an application that left it out fails in
        // the middle of somebody's message rather than at boot. The command line front end is the
        // one every context has; #14 is where a second one has to be chosen rather than assumed.
        // Singleton because what it hands over is the process's own streams.
        $this->bind(ChatEgress::class)->toProvider(StandardStreamsProvider::class)->in(Scope::SINGLETON);
        // What a front end resolves, in place of the parts it would otherwise put together itself.
        $this->bind(Conversation::class);
        // Bound although no constructor in this module names it: the pipeline receives it through
        // Be, which asks the injector for it while a message is being resolved, and a compiled
        // injector answers only for what was bound when it was compiled.
        $this->bind(ThreadIdFactory::class);
        $this->bind(ClaudeCliSettings::class);
        $this->bind(LifecycleSettings::class);
        $this->bind('')->annotatedWith(TurnSeconds::class)->toProvider(TurnSecondsProvider::class);
        $this->bind('')->annotatedWith(CloseGraceSeconds::class)->toProvider(CloseGraceSecondsProvider::class);
        // The parts an execution layer is assembled from, rather than ones it builds for itself.
        // Bound here and not where a runner is chosen, because they are the same parts whichever
        // runner that is, and a compiled injector answers only for what was bound.
        $this->bind(ClaudeCliEventParser::class);
        $this->bind(ClaudeCliCommand::class);
        $this->bind(ProcessRecipe::class);
        $this->bind(TurnLocks::class);
        $this->bind(ProcessPool::class);
        // Singleton because the runner is the pool: a second one would hold its own children and
        // neither would honour the other's limit.
        $this->bind(AgentRunner::class)->to(PersistentCliRunner::class)->in(Scope::SINGLETON);
        $this->install(new ResourceModule(AgentBridge::APP_NAME));
        $this->install(new ResourceObjectModule(self::RESOURCES));
    }
}
