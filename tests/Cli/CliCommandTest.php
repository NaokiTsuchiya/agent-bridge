<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Cli;

use NaokiTsuchiya\AgentBridge\Cli\CliCommand;
use NaokiTsuchiya\AgentBridge\Di\ServeContext;
use NaokiTsuchiya\AgentBridge\Tests\Support\TempDir;
use NaokiTsuchiya\RayDiContext\ContextProviderInterface;
use NaokiTsuchiya\RayDiContext\Exception\ExceptionInterface;
use NaokiTsuchiya\RayDiContext\MapContextProvider;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fopen;
use function putenv;
use function stream_get_contents;

/**
 * The two things the command decides before anything is started: whether it was called properly,
 * and where the compiled scripts are.
 *
 * Driven in process rather than as a child, because what these cases are about is the explanation
 * on the error stream; as a child they would shrink to an exit code.
 */
final class CliCommandTest extends TestCase
{
    /** Where the compiled scripts are looked for; every case here points it somewhere empty. */
    private string $appDir = '';

    /** {@inheritDoc} */
    #[Override]
    protected function setUp(): void
    {
        $this->appDir = TempDir::make('cli-app');
    }

    /** {@inheritDoc} */
    #[Override]
    protected function tearDown(): void
    {
        putenv(CliCommand::APP_DIR);
        TempDir::remove($this->appDir);
    }

    /**
     * A command line that names no single thread is refused before anything is booted.
     *
     * @param list<string> $argv what the process was called with, program name and all
     */
    #[DataProvider('badInvocations')]
    #[Test]
    public function refusesACommandLineThatNamesNoThread(array $argv): void
    {
        $errors = self::memory();

        $code = self::command('/nowhere', $errors)->run($argv);

        self::assertSame(2, $code);
        self::assertStringContainsString('usage: agent-bridge-cli THREAD_ID', self::explanation($errors));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function badInvocations(): iterable
    {
        yield 'no argument at all' => [['agent-bridge-cli']];
        // Not the same as a missing argument: an id is there, and it is empty.
        yield 'an empty thread id' => [['agent-bridge-cli', '']];
        // The prompt belongs on standard input; answering "hello" and dropping "world" would be
        // worse than saying so.
        yield 'a prompt left on the command line' => [['agent-bridge-cli', 'cli:x', 'hello world']];
    }

    /**
     * Without compiled scripts there is nothing to resolve from, and the directory that was looked
     * in is the one thing worth saying.
     */
    #[Test]
    public function refusesToStartWithoutCompiledScripts(): void
    {
        $variable = CliCommand::APP_DIR;
        putenv("{$variable}={$this->appDir}");
        $errors = self::memory();

        $code = self::command('/nowhere', $errors)->run(['agent-bridge-cli', 'cli:x']);

        self::assertSame(3, $code);
        self::assertStringContainsString("{$this->appDir}/var/di/" . ServeContext::NAME, self::explanation($errors));
    }

    /**
     * With nothing in the environment the scripts are looked for under the project itself, which
     * is where `composer compile` writes them.
     */
    #[Test]
    public function looksUnderTheProjectWhenTheEnvironmentNamesNoDirectory(): void
    {
        $root = "{$this->appDir}/project";
        $errors = self::memory();

        $code = self::command($root, $errors)->run(['agent-bridge-cli', 'cli:x']);

        self::assertSame(3, $code);
        self::assertStringContainsString("{$root}/var/di/" . ServeContext::NAME, self::explanation($errors));
    }

    /**
     * @param resource $errors what the command was given to explain itself on
     *
     * @return string everything it wrote there
     */
    private static function explanation(mixed $errors): string
    {
        $written = stream_get_contents($errors, offset: 0);
        self::assertIsString($written);

        return $written;
    }

    /**
     * @param string   $projectRoot where the scripts are looked for when the environment is silent
     * @param resource $errors      where the refusal is to be written
     */
    private static function command(string $projectRoot, mixed $errors): CliCommand
    {
        return new CliCommand(self::contexts(), $projectRoot, self::memory(), $errors);
    }

    /** @return resource a stream that keeps what was written to it, for the case to read back */
    private static function memory(): mixed
    {
        $stream = fopen('php://memory', mode: 'w+b');
        self::assertIsResource($stream);

        return $stream;
    }

    /**
     * The same mapping bootstrap.php returns, built here rather than read from there: what a
     * started process does with that file is covered by {@see CliRoundTripTest}, which runs the
     * script itself.
     *
     * @return ContextProviderInterface the context-name-to-context mapping
     */
    private static function contexts(): ContextProviderInterface
    {
        try {
            return new MapContextProvider([ServeContext::NAME => ServeContext::class]);
        } catch (ExceptionInterface $refused) {
            // The map names one class of this repository's own, so it cannot be refused.
            self::fail($refused->getMessage());
        }
    }
}
