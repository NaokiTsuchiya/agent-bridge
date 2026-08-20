<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use NaokiTsuchiya\AgentBridge\Di\BootException;
use NaokiTsuchiya\AgentBridge\Di\SlackContext;
use NaokiTsuchiya\AgentBridge\Process\AppBoot;
use NaokiTsuchiya\AgentBridge\Process\ErrorStream;
use NaokiTsuchiya\AgentBridge\Process\ExitCode;
use NaokiTsuchiya\RayDiContext\ContextProviderInterface;
use NaokiTsuchiya\RayDiContext\Exception\ExceptionInterface;

use function count;
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
    /** What a caller who got the command line wrong is told, newline included. */
    private const string USAGE = <<<'TEXT'
        usage: agent-bridge-slack [APP_DIR]

        APP_DIR is where the compiled DI scripts are read from, and defaults to the
        directory this program was installed under.

        SLACK_APP_TOKEN, SLACK_BOT_TOKEN and SLACK_BOT_USER_ID come from the
        environment, because they are secrets; see docs/slack-adapter.md.

        TEXT;

    /** Where a refusal is written. */
    private ErrorStream $errors;

    /** How this process is brought up. */
    private AppBoot $boot;

    /**
     * @param ContextProviderInterface $contexts    the context-name-to-context mapping, as
     *                                              bootstrap.php returns it
     * @param string                   $projectRoot where the compiled scripts are looked for when
     *                                              the command line does not name a directory
     * @param resource                 $errors      where a refusal is explained
     */
    public function __construct(
        ContextProviderInterface $contexts,
        private string $projectRoot,
        mixed $errors,
    ) {
        $this->errors = new ErrorStream($errors);
        $this->boot = new AppBoot($contexts);
    }

    /**
     * @param list<string> $argv the process argv, including the program name at index 0
     *
     * @return int the exit code: 0 stopped, 2 bad invocation, 3 cannot start
     */
    public function run(array $argv): int
    {
        return $this->outcome($argv)->value;
    }

    /** @param list<string> $argv the process argv, including the program name at index 0 */
    private function outcome(array $argv): ExitCode
    {
        // One optional argument and no more: a second one is somebody expecting this to take
        // something it does not, and guessing which of the two they meant would be worse.
        if (count($argv) > 2) {
            $this->errors->complain(self::USAGE);

            return ExitCode::BadInvocation;
        }

        $named = $argv[1] ?? '';
        $server = $this->server($named === '' ? $this->projectRoot : $named);

        if ($server === null) {
            return ExitCode::CannotStart;
        }

        // The connection, the queue and every turn wait on channels, so there has to be one
        // coroutine around all of it. It returns when the server's own coroutines have.
        run(static function () use ($server): void {
            $server->run();
        });

        return ExitCode::Ok;
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
            $injector = $this->boot->injector($appDir, SlackContext::NAME);

            return $injector->getInstance(SlackServer::class);
        } catch (BootException|ExceptionInterface|SlackException|SocketModeException $failure) {
            $this->errors->explain($failure);

            return null;
        }
    }
}
