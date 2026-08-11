<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Cli;

use Be\Framework\BecomingInterface;
use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Di\Boot;
use NaokiTsuchiya\AgentBridge\Di\BootException;
use NaokiTsuchiya\AgentBridge\Di\ServeContext;
use NaokiTsuchiya\AgentBridge\Pipeline\CompletedTurn;
use NaokiTsuchiya\AgentBridge\Runner\AgentRunner;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use NaokiTsuchiya\RayDiContext\AppMeta;
use NaokiTsuchiya\RayDiContext\ContextProviderInterface;
use NaokiTsuchiya\RayDiContext\Exception\ExceptionInterface;
use Ray\Di\InjectorInterface;
use Throwable;

use function count;
use function fwrite;
use function getenv;
use function is_string;
use function Swoole\Coroutine\run;

/**
 * One command line conversation: a thread named in an argument, its messages on standard input.
 *
 * What this exists to prove is that nothing between a message and an answered turn is written by
 * hand. It asks the injector for {@see BecomingInterface} and hands each message to it; the
 * worktree, the session, the child process and the streaming of the reply are all reached through
 * that one call.
 *
 * @api
 */
final class CliCommand
{
    /** The environment variable naming the directory the compiled scripts live under. */
    public const string APP_DIR = 'AGENT_BRIDGE_APP_DIR';

    /** Every turn was answered and every one of them went well. */
    private const int OK = 0;

    /** The conversation happened but at least one turn did not finish well. */
    private const int TURN_FAILED = 1;

    /** Nothing was attempted: the command line does not name a thread that can be used. */
    private const int BAD_INVOCATION = 2;

    /** Nothing was attempted: this process cannot be brought up at all. */
    private const int CANNOT_START = 3;

    /** What a caller who got the command line wrong is told, newline included. */
    private const string USAGE = <<<'TEXT'
        usage: agent-bridge-cli THREAD_ID

        THREAD_ID is "PLATFORM:NATIVE_ID", e.g. cli:my-experiment. One line of standard
        input is one message; the answer is written to standard output.

        TEXT;

    /**
     * @param ContextProviderInterface $contexts    the context-name-to-context mapping, as
     *                                              bootstrap.php returns it
     * @param string                   $projectRoot where the compiled scripts are looked for
     *                                              unless the environment names another directory
     * @param resource                 $input       the messages to answer, one per line
     * @param resource                 $errors      where a refusal is explained
     */
    public function __construct(
        private ContextProviderInterface $contexts,
        private string $projectRoot,
        private mixed $input,
        private mixed $errors,
    ) {}

    /**
     * @param list<string> $argv the process argv, including the program name at index 0
     *
     * @return int the exit code: 0 answered, 1 a turn failed, 2 bad invocation, 3 cannot start
     */
    public function run(array $argv): int
    {
        $thread = $argv[1] ?? '';
        // An extra argument is a prompt somebody put on the command line; answering the first
        // word of it and dropping the rest would be worse than refusing.
        $named = $thread !== '' && count($argv) === 2;
        if (!$named) {
            $this->complain(self::USAGE);

            return self::BAD_INVOCATION;
        }

        $injector = $this->boot();
        if ($injector === null) {
            return self::CANNOT_START;
        }

        return $this->converse(
            $injector->getInstance(BecomingInterface::class),
            $injector->getInstance(AgentRunner::class),
            new StandardInputIngress($thread, $this->input),
        );
    }

    /** @return InjectorInterface|null null once the reason has been written to the error stream */
    private function boot(): ?InjectorInterface
    {
        try {
            return (new Boot(AppMeta::fromAppDir($this->appDir(), ServeContext::NAME), $this->contexts))();
        } catch (BootException|ExceptionInterface $failure) {
            $this->explain($failure);

            return null;
        }
    }

    /**
     * Answers every message the front end has, one at a time, and gives the child up afterwards.
     *
     * All of it happens inside one coroutine because the execution layer waits on channels, and
     * the child is let go of before it ends: the pool watches its processes on a coroutine of its
     * own, and `Swoole\Coroutine\run()` does not return while that watch is still running.
     *
     * @return int the exit code
     */
    private function converse(BecomingInterface $becoming, AgentRunner $runner, StandardInputIngress $ingress): int
    {
        $code = self::OK;
        // Nothing thrown inside a coroutine reaches the caller — it ends the process instead — so
        // every outcome is decided in here and carried out.
        run(function () use ($becoming, $runner, $ingress, &$code): void {
            $thread = null;
            try {
                foreach ($ingress->listen() as $message) {
                    $turn = $becoming($message);
                    $answered = $turn instanceof CompletedTurn && $turn->success;
                    $thread = $turn instanceof CompletedTurn ? $turn->workspace->thread : $thread;
                    $code = $answered ? $code : self::TURN_FAILED;
                }
            } catch (InvalidArgumentException $refused) {
                $this->explain($refused);
                $code = self::BAD_INVOCATION;
            } catch (Throwable $failure) {
                $this->explain($failure);
                $code = self::TURN_FAILED;
            } finally {
                $this->release($runner, $thread);
            }
        });

        return $code;
    }

    /** Ends the thread's child, which is what lets this process finish. */
    private function release(AgentRunner $runner, ?ThreadId $thread): void
    {
        if ($thread === null) {
            return;
        }

        $runner->close($thread);
    }

    /** @return string the directory the compiled scripts are read from */
    private function appDir(): string
    {
        $configured = getenv(self::APP_DIR);

        return is_string($configured) && $configured !== '' ? $configured : $this->projectRoot;
    }

    /** Says why nothing more will happen, on the stream a reader can read apart from the answer. */
    private function explain(Throwable $failure): void
    {
        $reason = $failure->getMessage();
        $this->complain("{$reason}\n");
    }

    /** Writes to the error stream, which is where everything that is not an answer goes. */
    private function complain(string $text): void
    {
        fwrite($this->errors, $text);
    }
}
