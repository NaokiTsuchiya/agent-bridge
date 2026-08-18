<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Runner;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Event\AgentError;
use NaokiTsuchiya\AgentBridge\Event\ClaudeCliEventParser;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliCommand;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliSettings;
use NaokiTsuchiya\AgentBridge\Runner\LifecycleSettings;
use NaokiTsuchiya\AgentBridge\Runner\PersistentCliRunner;
use NaokiTsuchiya\AgentBridge\Runner\SpawnCliRunner;
use NaokiTsuchiya\AgentBridge\Runner\TurnLocks;
use NaokiTsuchiya\AgentBridge\Tests\Support\ChildProcesses;
use NaokiTsuchiya\AgentBridge\Tests\Support\ClaudeBinary;
use NaokiTsuchiya\AgentBridge\Tests\Support\Coro;
use NaokiTsuchiya\AgentBridge\Tests\Support\TempDir;
use NaokiTsuchiya\AgentBridge\Tests\Support\Warnings;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Runtime;
use Throwable;

use function iterator_to_array;
use function putenv;

use const SWOOLE_HOOK_PROC;
use const SWOOLE_HOOK_STREAM_FUNCTION;

/**
 * Verifies turn failure outcomes when a runner fails to start a child process.
 */
final class LaunchFailureTest extends TestCase
{
    /** Swoole's hook flags as they were before this class ran. */
    private static int $hookFlags = 0;

    /** Where the fake keeps this case's state. */
    private string $home = '';

    /** The directory the children are started in. */
    private string $cwd = '';

    /** The claude binary to execute. */
    private string $binary = '';

    /** {@inheritDoc} */
    #[Override]
    public static function setUpBeforeClass(): void
    {
        self::$hookFlags = Runtime::getHookFlags();
    }

    /** {@inheritDoc} */
    #[Override]
    public static function tearDownAfterClass(): void
    {
        Runtime::setHookFlags(self::$hookFlags);
        if ((self::$hookFlags & SWOOLE_HOOK_PROC) !== 0) {
            Coroutine\run(static function (): void {});
        }
    }

    /** {@inheritDoc} */
    #[Override]
    protected function setUp(): void
    {
        $this->home = TempDir::make('launch-fail-home');
        $this->cwd = TempDir::make('launch-fail-cwd');
        putenv("FAKE_CLAUDE_HOME={$this->home}");
        $this->binary = ClaudeBinary::fromEnvironment();
        Runtime::setHookFlags(self::$hookFlags | SWOOLE_HOOK_PROC | SWOOLE_HOOK_STREAM_FUNCTION);
    }

    /** {@inheritDoc} */
    #[Override]
    protected function tearDown(): void
    {
        putenv('FAKE_CLAUDE_HOME');
        TempDir::remove($this->home);
        TempDir::remove($this->cwd);
        Runtime::setHookFlags(self::$hookFlags | SWOOLE_HOOK_PROC | SWOOLE_HOOK_STREAM_FUNCTION);
        Coroutine\run(static function (): void {});
    }

    /**
     * When the resident runner's first process fails to start, the turn is settled and returns notStarted error.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function aTurnWhoseFirstProcessCannotStartIsSettled(): void
    {
        $thread = new ThreadId('slack:launch.first.fail');
        $cwd = $this->cwd;
        $binary = $this->binary;

        Coro::run(static function () use ($thread, $cwd, $binary): void {
            $runner = new PersistentCliRunner(
                new HookOffBeforeLaunch($cwd, failFrom: 1),
                new ClaudeCliSettings(binary: $binary, closeGraceSeconds: 0.2),
                new ClaudeCliEventParser(),
                new LifecycleSettings(idleSeconds: 900.0, turnSeconds: 5.0, maxProcesses: 2),
            );

            $events = [];
            $warnings = Warnings::captured(static function () use ($runner, $thread, &$events): void {
                $events = iterator_to_array($runner->send($thread, 'hello'));
            });

            self::assertNotEmpty($warnings);
            self::assertCount(1, $events);
            $first = $events[0] ?? null;
            self::assertInstanceOf(AgentError::class, $first);
            self::assertSame('The agent could not be started for "slack:launch.first.fail".', $first->message);
            self::assertSame(0, $runner->liveProcesses());
            self::assertSame([], ChildProcesses::all());
        });
    }

    /**
     * When the spawn runner's process fails to start, it yields notStarted and finishes the turn.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function aSpawnedTurnWhoseProcessCannotStartFails(): void
    {
        $thread = new ThreadId('slack:spawn.fail');
        $cwd = $this->cwd;
        $binary = $this->binary;

        Coro::run(static function () use ($thread, $cwd, $binary): void {
            $runner = new SpawnCliRunner(
                new HookOffBeforeLaunch($cwd, failFrom: 1),
                new ClaudeCliCommand(new ClaudeCliSettings(binary: $binary)),
                new ClaudeCliEventParser(),
                new TurnLocks(),
                5.0,
            );

            $events = [];
            $warnings = Warnings::captured(static function () use ($runner, $thread, &$events): void {
                $events = iterator_to_array($runner->send($thread, 'hello'));
            });

            self::assertNotEmpty($warnings);
            self::assertCount(1, $events);
            $first = $events[0] ?? null;
            self::assertInstanceOf(AgentError::class, $first);
            self::assertSame('The agent could not be started for "slack:spawn.fail".', $first->message);
            self::assertSame([], ChildProcesses::all());
        });
    }

    /**
     * When a turn's second process cannot be started during resume fallback, it fails cleanly.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function aTurnWhoseSecondProcessCannotStartFails(): void
    {
        $thread = new ThreadId('slack:launch.second.fail');
        $cwd = $this->cwd;
        $binary = $this->binary;

        Coro::run(static function () use ($thread, $cwd, $binary): void {
            $runner = new PersistentCliRunner(
                new HookOffBeforeLaunch($cwd, failFrom: 2),
                new ClaudeCliSettings(binary: $binary, closeGraceSeconds: 0.2),
                new ClaudeCliEventParser(),
                new LifecycleSettings(idleSeconds: 900.0, turnSeconds: 5.0, maxProcesses: 2),
            );

            $events = [];
            $warnings = Warnings::captured(static function () use ($runner, $thread, &$events): void {
                $events = iterator_to_array($runner->send($thread, 'hello'));
            });

            self::assertNotEmpty($warnings);
            self::assertCount(1, $events);
            $first = $events[0] ?? null;
            self::assertInstanceOf(AgentError::class, $first);
            self::assertSame('The agent could not be started for "slack:launch.second.fail".', $first->message);
            self::assertSame(0, $runner->liveProcesses());
            self::assertSame([], ChildProcesses::all());
        });
    }
}
