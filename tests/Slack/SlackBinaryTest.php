<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use NaokiTsuchiya\AgentBridge\Di\SlackContext;
use NaokiTsuchiya\AgentBridge\Slack\SlackApiClientProvider;
use NaokiTsuchiya\AgentBridge\Slack\SlackApiEndpointProvider;
use NaokiTsuchiya\AgentBridge\Slack\SlackAppToken;
use NaokiTsuchiya\AgentBridge\Slack\SlackAppTokenFactory;
use NaokiTsuchiya\AgentBridge\Slack\SlackBotToken;
use NaokiTsuchiya\AgentBridge\Slack\SlackIdentityProvider;
use NaokiTsuchiya\AgentBridge\Tests\Support\CliProcess;
use NaokiTsuchiya\AgentBridge\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function escapeshellarg;
use function exec;
use function implode;

use const PHP_BINARY;

/**
 * `bin/agent-bridge-slack` started as a real process.
 *
 * {@see SlackCommandTest} drives the command with an argument list it builds itself, which leaves
 * one thing untested: whether the process hands its own `$argv` over unchanged. A binary that
 * dropped the argument, or passed only the program name, would answer every case in that class the
 * same way and still look in the wrong directory when a person ran it.
 *
 * No case here needs a Slack workspace or a network: each stops before one is reached — the two
 * argument cases before anything is built, the endpoint case while the wiring is being built —
 * which is why they belong in the unit group rather than with the integration ones.
 */
final class SlackBinaryTest extends TestCase
{
    /** How long a process that only prints a refusal may take. */
    private const float PATIENCE = 30.0;

    /** The argument reaches the process and decides where it looks for the compiled scripts. */
    #[Test]
    public function looksInTheDirectoryTheCommandLineNames(): void
    {
        $empty = TempDir::make('slack-binary');

        [$code, $errors] = self::start([$empty]);

        TempDir::remove($empty);
        self::assertSame(3, $code);
        self::assertStringContainsString($empty, $errors, 'The process looked somewhere else.');
    }

    /** A second argument is refused before anything is attempted. */
    #[Test]
    public function refusesASecondArgument(): void
    {
        [$code, $errors] = self::start(['a', 'b']);

        self::assertSame(2, $code);
        self::assertStringContainsString('usage: agent-bridge-slack [APP_DIR]', $errors);
    }

    /**
     * A port that is not one ends the start, with the variable named and the value quoted back.
     *
     * The tokens are shaped like real ones so that nothing else can be what stops the process; the
     * refusal has to be the endpoint's. Nothing is dialled: the connector is refused as it is
     * built, which is before any client exists.
     */
    #[Test]
    public function refusesAPortThatIsNotAPort(): void
    {
        $appDir = TempDir::make('slack-binary-compiled');
        [$compiled, $output] = self::compile($appDir);
        self::assertSame(0, $compiled, "Compiling the slack context failed: {$output}");

        [$code, $errors] = self::start([$appDir], [
            SlackApiEndpointProvider::PORT_VARIABLE => 'not-a-port',
            SlackAppTokenFactory::ENVIRONMENT_VARIABLE => SlackAppToken::PREFIX . 'shaped-like-one',
            SlackApiClientProvider::ENVIRONMENT_VARIABLE => SlackBotToken::PREFIX . 'shaped-like-one',
            SlackIdentityProvider::ENVIRONMENT_VARIABLE => 'U0BOT',
        ]);

        TempDir::remove($appDir);
        self::assertSame(3, $code);
        self::assertStringContainsString(SlackApiEndpointProvider::PORT_VARIABLE, $errors);
        self::assertStringContainsString('"not-a-port"', $errors);
    }

    /**
     * Compiles the Slack context into an app dir, the way a deployment produces one.
     *
     * @return array{int, string} the exit code and the combined output of the compile
     */
    private static function compile(string $appDir): array
    {
        $root = dirname(__DIR__, levels: 2);
        $command = implode(' ', [
            PHP_BINARY,
            escapeshellarg("{$root}/vendor/bin/ray-di-compile"),
            escapeshellarg("{$root}/bootstrap.php"),
            escapeshellarg($appDir),
            escapeshellarg(SlackContext::NAME),
        ]);

        $output = [];
        $exitCode = 0;
        exec("{$command} 2>&1", $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }

    /**
     * @param list<string>          $arguments what to put after the program name
     * @param array<string, string> $env       added to this process's environment, not replacing it
     *
     * @return array{int, string} the exit code and everything written to the error stream
     */
    private static function start(array $arguments, array $env = []): array
    {
        $root = dirname(__DIR__, levels: 2);
        $process = CliProcess::start([PHP_BINARY, "{$root}/bin/agent-bridge-slack", ...$arguments], $root, $env);
        $process->closeStdin();

        $code = $process->waitForExit(self::PATIENCE);
        $errors = $process->stderr();
        $process->stop();

        self::assertIsInt($code, 'The process never ended.');

        return [$code, $errors];
    }
}
