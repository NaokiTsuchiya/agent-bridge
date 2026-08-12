<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Cli;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Chat\ChatIngress;
use NaokiTsuchiya\AgentBridge\Di\Boot;
use NaokiTsuchiya\AgentBridge\Di\BootException;
use NaokiTsuchiya\AgentBridge\Di\ServeContext;
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
 * All this does is turn a command line into the three things a conversation needs — a thread to
 * hold it in, somewhere to read the messages from, and a process that can answer them — and turn
 * how it went into an exit code. Answering is {@see Conversation}'s, and it is asked of the
 * injector as one object: a front end that resolved its collaborators one by one would be deciding
 * how the conversation is put together, which is the module's business, not a command's.
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
            $injector->getInstance(Conversation::class),
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
     * Holds the conversation, inside the one coroutine this process ever starts.
     *
     * The execution layer waits on channels, so there has to be one; and everything the
     * conversation has to say for itself comes back as a value, because nothing thrown inside a
     * coroutine reaches the caller — it ends the process instead.
     *
     * @return int the exit code
     */
    private function converse(Conversation $conversation, ChatIngress $ingress): int
    {
        $code = self::OK;
        $failure = null;
        run(static function () use ($conversation, $ingress, &$code, &$failure): void {
            $result = $conversation->answer($ingress);
            $code = self::codeFor($result);
            $failure = $result->failure;
        });

        if ($failure !== null) {
            $this->explain($failure);
        }

        return $code;
    }

    /** @return int the exit code that outcome deserves */
    private static function codeFor(ConversationResult $result): int
    {
        $failure = $result->failure;
        if ($failure === null) {
            return $result->answered ? self::OK : self::TURN_FAILED;
        }

        // A message that names no usable thread is the caller's mistake, and reads as one; anything
        // else went wrong while the answer was being produced.
        return $failure instanceof InvalidArgumentException ? self::BAD_INVOCATION : self::TURN_FAILED;
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
