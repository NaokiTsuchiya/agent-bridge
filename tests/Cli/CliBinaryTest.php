<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Cli;

use NaokiTsuchiya\AgentBridge\Di\ServeContext;
use NaokiTsuchiya\AgentBridge\Support\CliProcess;
use NaokiTsuchiya\AgentBridge\Support\TempDir;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;

use const PHP_BINARY;

/** `bin/agent-bridge-cli` started as a real process. */
final class CliBinaryTest extends TestCase
{
    /** How long a process that only prints a refusal is given. */
    private const float PATIENCE = 30.0;

    /** CliCommandTest proves the logic; this proves the exit code and stderr still carry it across a real process boundary. */
    #[Test]
    public function refusesACommandLineThatNamesNoThread(): void
    {
        [$code, $errors] = self::start([]);

        self::assertSame(2, $code);
        self::assertStringContainsString('usage: agent-bridge-cli THREAD_ID', $errors);
    }

    /** Same boundary-crossing check as above, for the other code nothing-attempted can end in. */
    #[Test]
    public function refusesToStartWithoutCompiledScripts(): void
    {
        $empty = TempDir::make('cli-binary');

        [$code, $errors] = self::start(['cli:x'], [CliCommand::APP_DIR => $empty]);

        TempDir::remove($empty);
        self::assertSame(3, $code);
        self::assertStringContainsString("{$empty}/var/di/" . ServeContext::NAME, $errors);
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
        $process = CliProcess::start([PHP_BINARY, "{$root}/bin/agent-bridge-cli", ...$arguments], $root, $env);
        $process->closeStdin();

        $code = $process->waitForExit(self::PATIENCE);
        $errors = $process->stderr();
        $process->stop();

        self::assertIsInt($code, 'The process never ended.');

        return [$code, $errors];
    }
}
