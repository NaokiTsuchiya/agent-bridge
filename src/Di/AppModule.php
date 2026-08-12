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
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliSettings;
use NaokiTsuchiya\AgentBridge\Runner\LifecycleSettings;
use NaokiTsuchiya\AgentBridge\Runner\PersistentCliRunner;
use NaokiTsuchiya\AgentBridge\Runner\WorkingDirectoryResolver;
use NaokiTsuchiya\AgentBridge\Runner\WorktreeWorkingDirectory;
use NaokiTsuchiya\AgentBridge\Thread\ThreadIdFactory;
use NaokiTsuchiya\AgentBridge\Worktree\WorktreeManager;
use Override;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;
use ReflectionException;

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

    /** The binding name the repository path is published under, since a `string` cannot be one. */
    private const string BASE_REPOSITORY = 'base_repository';

    /**
     * {@inheritDoc}
     *
     * @throws ReflectionException When toConstructor() is given a class it cannot reflect on, which
     *         the class named right there is not.
     */
    #[Override]
    protected function configure(): void
    {
        $this->bind('')->annotatedWith(self::BASE_REPOSITORY)->toProvider(BaseRepositoryProvider::class);
        $this->bind(GitInterface::class)->to(Git::class);
        // toConstructor rather than an attribute on the parameter: the manager is plain PHP and
        // stays that way, so nothing outside this file knows the binding name above.
        $this->bind(WorktreeManager::class)->toConstructor(WorktreeManager::class, [
            'baseRepository' => self::BASE_REPOSITORY,
        ]);
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
        // What every execution layer needs to make sense of the binary's output, and what an
        // execution layer that takes it as a required constructor argument cannot be built without.
        $this->bind(ClaudeCliEventParser::class);
        // Singleton because the runner is the pool: a second one would hold its own children and
        // neither would honour the other's limit.
        $this->bind(AgentRunner::class)->to(PersistentCliRunner::class)->in(Scope::SINGLETON);
        $this->install(new ResourceModule(AgentBridge::APP_NAME));
        $this->install(new ResourceObjectModule(self::RESOURCES));
    }
}
