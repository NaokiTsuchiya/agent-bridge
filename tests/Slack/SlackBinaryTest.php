<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use NaokiTsuchiya\AgentBridge\Tests\Support\CliProcess;
use NaokiTsuchiya\AgentBridge\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;

use const PHP_BINARY;

/**
 * `bin/agent-bridge-slack` started as a real process.
 *
 * {@see SlackCommandTest} drives the command with an argument list it builds itself, which leaves
 * one thing untested: whether the process hands its own `$argv` over unchanged. A binary that
 * dropped the argument, or passed only the program name, would answer every case in that class the
 * same way and still look in the wrong directory when a person ran it.
 *
 * Neither case needs a Slack token or a network: both stop before a workspace is ever reached,
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
     * @param list<string> $arguments what to put after the program name
     *
     * @return array{int, string} the exit code and everything written to the error stream
     */
    private static function start(array $arguments): array
    {
        $root = dirname(__DIR__, levels: 2);
        $process = CliProcess::start([PHP_BINARY, "{$root}/bin/agent-bridge-slack", ...$arguments], $root);
        $process->closeStdin();

        $code = $process->waitForExit(self::PATIENCE);
        $errors = $process->stderr();
        $process->stop();

        self::assertIsInt($code, 'The process never ended.');

        return [$code, $errors];
    }
}
