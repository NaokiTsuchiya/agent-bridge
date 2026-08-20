<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Resource\App;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\AgentBridge;
use NaokiTsuchiya\AgentBridge\Di\ServeContext;
use NaokiTsuchiya\AgentBridge\Event\ClaudeCliEventParser;
use NaokiTsuchiya\AgentBridge\Resource\App\Health;
use NaokiTsuchiya\AgentBridge\Runner\AgentRunner;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliCommand;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliSettings;
use NaokiTsuchiya\AgentBridge\Runner\LifecycleSettings;
use NaokiTsuchiya\AgentBridge\Runner\PersistentCliRunner;
use NaokiTsuchiya\AgentBridge\Runner\ProcessPool;
use NaokiTsuchiya\AgentBridge\Runner\ProcessRecipe;
use NaokiTsuchiya\AgentBridge\Runner\TurnLocks;
use NaokiTsuchiya\AgentBridge\Tests\Di\CompiledServe;
use NaokiTsuchiya\AgentBridge\Tests\Di\SpawnServeContext;
use NaokiTsuchiya\AgentBridge\Tests\Pipeline\StubAgentRunner;
use NaokiTsuchiya\AgentBridge\Tests\Runner\Events;
use NaokiTsuchiya\AgentBridge\Tests\Runner\FakeCliRunnerTestCase;
use NaokiTsuchiya\AgentBridge\Tests\Runner\FixedWorkingDirectory;
use NaokiTsuchiya\AgentBridge\Tests\Support\ClaudeBinary;
use NaokiTsuchiya\AgentBridge\Tests\Support\Coro;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use NaokiTsuchiya\RayDiContext\Exception\CompileDirUnavailable;
use NaokiTsuchiya\RayDiContext\Exception\InvalidAppMeta;
use NaokiTsuchiya\RayDiContext\InjectorBuilder;
use Override;
use PHPUnit\Framework\Attributes\Test;
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
        $runner = new StubAgentRunner([]);
        $health = new Health($runner);

        $health->onGet();

        self::assertSame(['status' => 'ok', 'package' => AgentBridge::PACKAGE, 'processes' => 0], $health->body);
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

        $health = new Health($runner);

        $health->onGet();
        self::assertSame(['status' => 'ok', 'package' => AgentBridge::PACKAGE, 'processes' => 3], $health->body);
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

        $health = new Health($runner);

        $health->onGet();
        self::assertSame(['status' => 'ok', 'package' => AgentBridge::PACKAGE, 'processes' => 0], $health->body);

        $runner->count = 2;
        $health->onGet();
        self::assertSame(['status' => 'ok', 'package' => AgentBridge::PACKAGE, 'processes' => 2], $health->body);
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
        $health = new Health($runner);
        $thread = $this->thread('slack:1800000001.000100');

        Coro::run(static function () use ($runner, $health, $thread): void {
            // Before turn: 0 processes
            $health->onGet();
            self::assertSame(['status' => 'ok', 'package' => AgentBridge::PACKAGE, 'processes' => 0], $health->body);

            // Send prompt: process starts and remains alive in pool after turn
            Events::collect($runner->send($thread, 'hello'));

            $health->onGet();
            self::assertSame(['status' => 'ok', 'package' => AgentBridge::PACKAGE, 'processes' => 1], $health->body);

            // Close thread: process ends, count drops back to 0
            $runner->close($thread);
            $health->onGet();
            self::assertSame(['status' => 'ok', 'package' => AgentBridge::PACKAGE, 'processes' => 0], $health->body);
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

        $response = $resource->uri('app://self/health')();

        self::assertInstanceOf(ResourceObject::class, $response);
        self::assertSame(['status' => 'ok', 'package' => AgentBridge::PACKAGE, 'processes' => 0], $response->body);
    }

    /**
     * @return ThreadId a thread whose session is seeded in the fake's store
     *
     * @throws InvalidArgumentException
     */
    private function thread(string $id): ThreadId
    {
        $thread = new ThreadId($id);
        $this->seedSession($thread);

        return $thread;
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

        $response = $resource->uri('app://self/health')();

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
}
