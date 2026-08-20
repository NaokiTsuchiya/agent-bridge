<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Runner;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Event\AgentError;
use NaokiTsuchiya\AgentBridge\Event\ClaudeCliEventParser;
use NaokiTsuchiya\AgentBridge\Event\TurnCompleted;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliCommand;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliSettings;
use NaokiTsuchiya\AgentBridge\Runner\LifecycleSettings;
use NaokiTsuchiya\AgentBridge\Runner\PersistentCliRunner;
use NaokiTsuchiya\AgentBridge\Runner\ProcessPool;
use NaokiTsuchiya\AgentBridge\Runner\ProcessRecipe;
use NaokiTsuchiya\AgentBridge\Runner\TurnLocks;
use NaokiTsuchiya\AgentBridge\Tests\Support\ChildProcesses;
use NaokiTsuchiya\AgentBridge\Tests\Support\Coro;
use NaokiTsuchiya\AgentBridge\Tests\Support\TempDir;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Runtime;
use Throwable;

use function chmod;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function is_string;
use function iterator_to_array;
use function shell_exec;
use function str_contains;
use function trim;

use const SWOOLE_HOOK_PROC;
use const SWOOLE_HOOK_STREAM_FUNCTION;

/**
 * Verifies turn settlement when a process dies after answering or when close() races reading.
 */
final class TurnSettlementTest extends TestCase
{
    /** Swoole's hook flags as they were before this class ran. */
    private static int $hookFlags = 0;

    /** Where the fake keeps this case's state. */
    private string $home = '';

    /** The directory the children are started in. */
    private string $cwd = '';

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
        $this->home = TempDir::make('turn-settle-home');
        $this->cwd = TempDir::make('turn-settle-cwd');
        FakeCliHome::activate($this->home);
        Runtime::setHookFlags(self::$hookFlags | SWOOLE_HOOK_PROC | SWOOLE_HOOK_STREAM_FUNCTION);
    }

    /** {@inheritDoc} */
    #[Override]
    protected function tearDown(): void
    {
        FakeCliHome::deactivate();
        TempDir::remove($this->home);
        TempDir::remove($this->cwd);
    }

    /**
     * A process that completed a turn but died before settlement is discarded from the pool.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function aProcessThatDiedAfterAnsweringIsDiscarded(): void
    {
        $pidFile = "{$this->home}/child.pid";
        $script = "{$this->home}/dies-after-answering";
        file_put_contents($script, <<<SH
            #!/bin/sh
            echo \$\$ > "{$pidFile}"
            printf '{"type":"result","subtype":"success","is_error":false,"session_id":"test","result":"ok"}\\n'
            exit 0
            SH);
        chmod($script, permissions: 0o755);

        $cwd = $this->cwd;
        $thread = new ThreadId('slack:settle.died');

        Coro::run(static function () use ($script, $cwd, $thread, $pidFile): void {
            $settings = new ClaudeCliSettings(binary: $script, closeGraceSeconds: 0.2);
            $limits = new LifecycleSettings(idleSeconds: 900.0, turnSeconds: 5.0, maxProcesses: 2);
            $runner = new PersistentCliRunner(
                new ProcessRecipe(new FixedWorkingDirectory($cwd), new ClaudeCliCommand($settings)),
                new ClaudeCliEventParser(),
                new TurnLocks(),
                new ProcessPool($limits, $settings->closeGraceSeconds),
                $limits->turnSeconds,
            );

            $events = $runner->send($thread, 'hello');

            while (true) {
                $exists = file_exists($pidFile);
                if ($exists) {
                    $size = filesize($pidFile);
                    if ($size !== false && $size > 0) {
                        break;
                    }
                }

                Coroutine::sleep(0.005);
            }

            $content = file_get_contents($pidFile);
            self::assertIsString($content);
            $pid = (int) trim($content);

            while (true) {
                $stat = shell_exec("ps -p {$pid} -o stat=");
                if (!is_string($stat) || str_contains($stat, 'Z') || trim($stat) === '') {
                    break;
                }

                Coroutine::sleep(0.005);
            }

            $collected = iterator_to_array($events);
            self::assertCount(1, $collected);
            $first = $collected[0] ?? null;
            self::assertInstanceOf(TurnCompleted::class, $first);
            self::assertTrue($first->success);
            self::assertSame(0, $runner->liveProcesses());

            $runner->close($thread);
            self::assertSame(0, $runner->liveProcesses());
            self::assertSame([], ChildProcesses::all());
        });
    }

    /**
     * When close() settles a turn first, the subsequent settlement by the turn reader does nothing.
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[Test]
    public function theSecondSettleOfATurnDoesNothing(): void
    {
        $script = "{$this->home}/silent-lingerer";
        file_put_contents($script, data: "#!/bin/sh\nexec sleep 5\n");
        chmod($script, permissions: 0o755);

        $cwd = $this->cwd;
        $thread = new ThreadId('slack:settle.race');

        Coro::run(static function () use ($script, $cwd, $thread): void {
            $settings = new ClaudeCliSettings(binary: $script, closeGraceSeconds: 0.05);
            $limits = new LifecycleSettings(idleSeconds: 900.0, turnSeconds: 0.4, maxProcesses: 2);
            $runner = new PersistentCliRunner(
                new ProcessRecipe(new FixedWorkingDirectory($cwd), new ClaudeCliCommand($settings)),
                new ClaudeCliEventParser(),
                new TurnLocks(),
                new ProcessPool($limits, $settings->closeGraceSeconds),
                $limits->turnSeconds,
            );

            $channel = new Channel(2);
            $readerEvents = [];

            Coroutine::create(static function () use ($runner, $thread, &$readerEvents, $channel): void {
                $readerEvents = iterator_to_array($runner->send($thread, 'hello'));
                $channel->push(true);
            });

            Coroutine::create(static function () use ($runner, $thread, $channel): void {
                $runner->close($thread);
                $channel->push(true);
            });

            $channel->pop(5.0);
            $channel->pop(5.0);

            self::assertCount(1, $readerEvents);
            $first = $readerEvents[0] ?? null;
            self::assertInstanceOf(AgentError::class, $first);
            self::assertSame(0, $runner->liveProcesses());
            self::assertSame([], ChildProcesses::all());
        });
    }
}
