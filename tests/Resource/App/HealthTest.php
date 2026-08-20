<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Resource\App;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\Module\ResourceObjectModule;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use NaokiTsuchiya\AgentBridge\AgentBridge;
use NaokiTsuchiya\AgentBridge\Di\CompiledServe;
use NaokiTsuchiya\AgentBridge\Di\ServeContext;
use NaokiTsuchiya\AgentBridge\Di\SpawnServeContext;
use NaokiTsuchiya\AgentBridge\Event\ClaudeCliEventParser;
use NaokiTsuchiya\AgentBridge\Pipeline\StubAgentRunner;
use NaokiTsuchiya\AgentBridge\Runner\AgentRunner;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliCommand;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliSettings;
use NaokiTsuchiya\AgentBridge\Runner\Events;
use NaokiTsuchiya\AgentBridge\Runner\FakeCliRunnerTestCase;
use NaokiTsuchiya\AgentBridge\Runner\FixedWorkingDirectory;
use NaokiTsuchiya\AgentBridge\Runner\LifecycleSettings;
use NaokiTsuchiya\AgentBridge\Runner\PersistentCliRunner;
use NaokiTsuchiya\AgentBridge\Runner\ProcessPool;
use NaokiTsuchiya\AgentBridge\Runner\ProcessRecipe;
use NaokiTsuchiya\AgentBridge\Runner\TurnLocks;
use NaokiTsuchiya\AgentBridge\Support\ClaudeBinary;
use NaokiTsuchiya\AgentBridge\Support\Coro;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use NaokiTsuchiya\RayDiContext\Exception\CompileDirUnavailable;
use NaokiTsuchiya\RayDiContext\Exception\InvalidAppMeta;
use NaokiTsuchiya\RayDiContext\InjectorBuilder;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Throwable;

final class HealthTest extends FakeCliRunnerTestCase
{
    /** @return string names this case's temp directories, so a stray one is easy to place */
    #[Override]
    protected function homePrefix(): string
    {
        return 'health';
    }

    /**
     * Answers 0 processes when the execution layer has no live processes.
     */
    #[Test]
    public function answersZeroWhenNoProcessesRunning(): void
    {
        $resource = $this->resource(new StubAgentRunner([]));

        $response = $resource->get('app://self/health');

        self::assertSame(['status' => 'ok', 'package' => AgentBridge::PACKAGE, 'processes' => 0], $response->body);
    }

    /**
     * Answers the live process count when the execution layer is holding processes.
     */
    #[Test]
    public function answersProcessCountWhenProcessesAreLive(): void
    {
        $runner = new class implements AgentRunner {
            public int $count = 3;

            #[Override]
            public function send(ThreadId $thread, string $prompt): iterable
            {
                return [];
            }

            #[Override]
            public function close(ThreadId $thread): void {}

            #[Override]
            public function liveProcesses(): int
            {
                return $this->count;
            }
        };

        $resource = $this->resource($runner);
        $response = $resource->get('app://self/health');

        self::assertSame(['status' => 'ok', 'package' => AgentBridge::PACKAGE, 'processes' => 3], $response->body);
    }

    /**
     * The body value changes when the execution layer's process count changes.
     */
    #[Test]
    public function bodyChangesWhenProcessCountChanges(): void
    {
        $runner = new class implements AgentRunner {
            public int $count = 0;

            #[Override]
            public function send(ThreadId $thread, string $prompt): iterable
            {
                return [];
            }

            #[Override]
            public function close(ThreadId $thread): void {}

            #[Override]
            public function liveProcesses(): int
            {
                return $this->count;
            }
        };

        $resource = $this->resource($runner);

        $first = $resource->get('app://self/health');
        self::assertSame(['status' => 'ok', 'package' => AgentBridge::PACKAGE, 'processes' => 0], $first->body);

        $runner->count = 2;
        $second = $resource->get('app://self/health');
        self::assertSame(['status' => 'ok', 'package' => AgentBridge::PACKAGE, 'processes' => 2], $second->body);
    }

    /**
     * With a resident runner, the health resource observes real process lifecycle transitions.
     *
     * @throws Throwable
     */
    #[Test]
    public function observesResidentProcessLifecycle(): void
    {
        $runner = $this->persistentRunner();
        $resource = $this->resource($runner);
        $thread = $this->thread('slack:1800000001.000100');

        Coro::run(static function () use ($runner, $resource, $thread): void {
            // Before turn: 0 processes
            $response = $resource->get('app://self/health');
            self::assertSame(['status' => 'ok', 'package' => AgentBridge::PACKAGE, 'processes' => 0], $response->body);

            // Send prompt: process starts and remains alive in pool after turn
            Events::collect($runner->send($thread, 'hello'));

            $response = $resource->get('app://self/health');
            self::assertSame(['status' => 'ok', 'package' => AgentBridge::PACKAGE, 'processes' => 1], $response->body);

            // Close thread: process ends, count drops back to 0
            $runner->close($thread);
            $response = $resource->get('app://self/health');
            self::assertSame(['status' => 'ok', 'package' => AgentBridge::PACKAGE, 'processes' => 0], $response->body);
        });
    }

    /**
     * With the spawn runner, the health resource always reports 0 processes.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     */
    #[Test]
    public function answersZeroWithSpawnRunner(): void
    {
        $meta = CompiledServe::spawnMeta();
        $injector = (new InjectorBuilder())(new SpawnServeContext($meta), $meta);
        $resource = $injector->getInstance(ResourceInterface::class);

        $response = $resource->get('app://self/health');

        self::assertInstanceOf(ResourceObject::class, $response);
        self::assertSame(['status' => 'ok', 'package' => AgentBridge::PACKAGE, 'processes' => 0], $response->body);
    }

    /**
     * Is accessible and functional over BEAR.Resource URI dispatch.
     *
     * @throws CompileDirUnavailable
     * @throws InvalidAppMeta
     */
    #[Test]
    public function isAccessibleOverResourceUri(): void
    {
        $meta = CompiledServe::meta();
        $injector = (new InjectorBuilder())(new ServeContext($meta), $meta);
        $resource = $injector->getInstance(ResourceInterface::class);

        $response = $resource->get('app://self/health');

        self::assertInstanceOf(ResourceObject::class, $response);
        self::assertSame(['status' => 'ok', 'package' => AgentBridge::PACKAGE, 'processes' => 0], $response->body);
    }

    /** @return PersistentCliRunner a resident runner pointed at the fake binary */
    private function persistentRunner(): PersistentCliRunner
    {
        $limits = new LifecycleSettings();
        $settings = new ClaudeCliSettings(binary: ClaudeBinary::fake(), closeGraceSeconds: 2.0);

        return new PersistentCliRunner(
            new ProcessRecipe(new FixedWorkingDirectory($this->cwd), new ClaudeCliCommand($settings)),
            new ClaudeCliEventParser(),
            new TurnLocks(),
            new ProcessPool($limits, $settings->closeGraceSeconds),
            $limits->turnSeconds,
        );
    }

    /** @return ResourceInterface a resource client bound to the given runner */
    private function resource(AgentRunner $runner): ResourceInterface
    {
        $module = new class($runner) extends AbstractModule {
            public function __construct(
                private AgentRunner $runner,
            ) {}

            #[Override]
            protected function configure(): void
            {
                $this->bind(AgentRunner::class)->toInstance($this->runner);
                $this->install(new ResourceModule(AgentBridge::APP_NAME));
                $this->install(new ResourceObjectModule([Health::class]));
            }
        };

        return new Injector($module)->getInstance(ResourceInterface::class);
    }
}
