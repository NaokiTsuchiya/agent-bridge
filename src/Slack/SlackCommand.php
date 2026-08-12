<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use NaokiTsuchiya\AgentBridge\Di\Boot;
use NaokiTsuchiya\AgentBridge\Di\BootException;
use NaokiTsuchiya\AgentBridge\Di\SlackContext;
use NaokiTsuchiya\RayDiContext\AppMeta;
use NaokiTsuchiya\RayDiContext\ContextProviderInterface;
use NaokiTsuchiya\RayDiContext\Exception\ExceptionInterface;
use Throwable;

use function count;
use function fwrite;
use function Swoole\Coroutine\run;

/**
 * The Slack front end as a process: connect, and answer until it is stopped.
 *
 * It asks the injector for {@see SlackServer} and nothing else, for the same reason
 * {@see \NaokiTsuchiya\AgentBridge\Cli\CliCommand} asks only for a conversation: how the front
 * end is put together is the module's business, and a command that resolved the parts itself would
 * be making that decision instead.
 *
 * **Where the compiled scripts are is a command line argument, not an environment variable.** The
 * three tokens are secrets and belong in the environment; a path is neither secret nor constant
 * across the deployments one machine may run, and naming it where the process is started is what
 * keeps two of them from having to share one variable.
 *
 * @api
 */
final class SlackCommand
{
    /** The server was stopped without anything having gone wrong. */
    private const int OK = 0;

    /** Nothing was attempted: the command line says something this program does not take. */
    private const int BAD_INVOCATION = 2;

    /** Nothing was attempted: this process cannot be brought up at all. */
    private const int CANNOT_START = 3;

    /** What a caller who got the command line wrong is told, newline included. */
    private const string USAGE = <<<'TEXT'
        usage: agent-bridge-slack [APP_DIR]

        APP_DIR is where the compiled DI scripts are read from, and defaults to the
        directory this program was installed under.

        SLACK_APP_TOKEN, SLACK_BOT_TOKEN and SLACK_BOT_USER_ID come from the
        environment, because they are secrets; see docs/slack-adapter.md.

        TEXT;

    /**
     * @param ContextProviderInterface $contexts    the context-name-to-context mapping, as
     *                                              bootstrap.php returns it
     * @param string                   $projectRoot where the compiled scripts are looked for when
     *                                              the command line does not name a directory
     * @param resource                 $errors      where a refusal is explained
     */
    public function __construct(
        private ContextProviderInterface $contexts,
        private string $projectRoot,
        private mixed $errors,
    ) {}

    /**
     * @param list<string> $argv the process argv, including the program name at index 0
     *
     * @return int the exit code: 0 stopped, 2 bad invocation, 3 cannot start
     */
    public function run(array $argv): int
    {
        // One optional argument and no more: a second one is somebody expecting this to take
        // something it does not, and guessing which of the two they meant would be worse.
        if (count($argv) > 2) {
            $this->complain(self::USAGE);

            return self::BAD_INVOCATION;
        }

        $named = $argv[1] ?? '';
        $server = $this->server($named === '' ? $this->projectRoot : $named);

        if ($server === null) {
            return self::CANNOT_START;
        }

        // The connection, the queue and every turn wait on channels, so there has to be one
        // coroutine around all of it. It returns when the server's own coroutines have.
        run(static function () use ($server): void {
            $server->run();
        });

        return self::OK;
    }

    /**
     * The server, or null once the reason there is none has been written to the error stream.
     *
     * The tokens are read while this is built, so a workspace that was never configured is refused
     * here rather than in the middle of somebody's message.
     *
     * @param string $appDir the directory the compiled scripts are read from
     */
    private function server(string $appDir): ?SlackServer
    {
        try {
            $injector = (new Boot(AppMeta::fromAppDir($appDir, SlackContext::NAME), $this->contexts))();

            return $injector->getInstance(SlackServer::class);
        } catch (BootException|ExceptionInterface|SlackException|SocketModeException $failure) {
            $this->explain($failure);

            return null;
        }
    }

    /** Says why nothing more will happen. */
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
