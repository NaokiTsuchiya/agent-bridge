<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Cli;

use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Di\FixedContext;
use NaokiTsuchiya\AgentBridge\Di\ServeContext;
use NaokiTsuchiya\AgentBridge\Pipeline\Completed;
use NaokiTsuchiya\AgentBridge\Pipeline\CompletedTurn;
use NaokiTsuchiya\AgentBridge\Pipeline\Failed;
use NaokiTsuchiya\AgentBridge\Pipeline\FailedTurn;
use NaokiTsuchiya\AgentBridge\Pipeline\IncomingMessage;
use NaokiTsuchiya\AgentBridge\Pipeline\StubAgentRunner;
use NaokiTsuchiya\AgentBridge\Support\TempDir;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use NaokiTsuchiya\AgentBridge\Thread\ThreadWorkspace;
use NaokiTsuchiya\RayDiContext\ContextProviderInterface;
use NaokiTsuchiya\RayDiContext\Exception\ExceptionInterface;
use NaokiTsuchiya\RayDiContext\MapContextProvider;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function array_map;
use function fopen;
use function fwrite;
use function putenv;
use function rewind;
use function stream_get_contents;

/**
 * What the command decides on its own: whether it was called properly, where the compiled scripts
 * are, and what the conversation it held deserves as an exit code.
 *
 * Driven in process rather than as a child, because what these cases are about is the explanation
 * on the error stream and the code that goes with it; as a child the explanation would be lost and
 * only the code would remain.
 *
 * @mago-expect lint:too-many-methods
 */
final class CliCommandTest extends TestCase
{
    /** The thread the conversing cases name on the command line. */
    private const string THREAD = 'cli:my-experiment';

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
     * Every line of standard input is a message of the thread the command line named, and a
     * conversation that went well is worth nothing on the error stream.
     *
     * @throws Throwable
     */
    #[Test]
    public function answersEveryLineOfStandardInput(): void
    {
        $becoming = new ScriptedBecoming([self::finishedTurn(), self::finishedTurn()]);
        $errors = self::memory();

        $code = $this->conversing($becoming, "first\nsecond\n", $errors)->run(['agent-bridge-cli', self::THREAD]);

        self::assertSame(0, $code);
        self::assertSame(['cli my-experiment first', 'cli my-experiment second'], self::handedOver($becoming->seen));
        self::assertSame('', self::explanation($errors));
    }

    /**
     * A turn that finished badly is an answer the reader has already seen, so it is a code and not
     * an explanation.
     *
     * @throws Throwable
     */
    #[Test]
    public function reportsATurnThatDidNotGoWell(): void
    {
        $errors = self::memory();

        $code = $this->conversing(new ScriptedBecoming([self::unfinishedTurn()]), "hello\n", $errors)->run([
            'agent-bridge-cli',
            self::THREAD,
        ]);

        self::assertSame(1, $code);
        self::assertSame('', self::explanation($errors), 'Nothing failed, so there is nothing to say.');
    }

    /**
     * An id the application refuses is the caller's mistake, and reads as one — the same code a
     * command line that names no thread gets.
     *
     * @throws Throwable
     */
    #[Test]
    public function refusesAThreadIdTheApplicationRejects(): void
    {
        $refused = new ScriptedBecoming([new InvalidArgumentException('not a thread')]);
        $errors = self::memory();

        $code = $this->conversing($refused, "hello\n", $errors)->run(['agent-bridge-cli', 'cli:']);

        self::assertSame(2, $code);
        self::assertStringContainsString('not a thread', self::explanation($errors));
    }

    /**
     * Anything else that ended the conversation went wrong while the answer was being produced,
     * which is not the caller's mistake.
     *
     * @throws Throwable
     */
    #[Test]
    public function reportsAnAgentThatDied(): void
    {
        $died = new ScriptedBecoming([new RuntimeException('the agent died')]);
        $errors = self::memory();

        $code = $this->conversing($died, "hello\n", $errors)->run(['agent-bridge-cli', self::THREAD]);

        self::assertSame(1, $code);
        self::assertStringContainsString('the agent died', self::explanation($errors));
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

    /**
     * A command that resolves the scripted chain instead of a compiled one, reading those lines.
     *
     * Booting reads the mapping it was given and nothing else, which is what lets a case get past
     * the point where a real process would need `composer compile` behind it.
     *
     * @param string   $input  the standard input, one message per line
     * @param resource $errors where the command explains itself
     */
    private function conversing(ScriptedBecoming $becoming, string $input, mixed $errors): CliCommand
    {
        $conversation = new Conversation($becoming, new StubAgentRunner([]));

        return new CliCommand(
            new FixedContext([Conversation::class => $conversation]),
            $this->appDir,
            self::lines($input),
            $errors,
        );
    }

    /**
     * @param list<IncomingMessage> $messages
     *
     * @return list<string> each as the three things the chain was given
     */
    private static function handedOver(array $messages): array
    {
        return array_map(
            static fn(IncomingMessage $message): string => "{$message->platform} {$message->nativeId} {$message->text}",
            $messages,
        );
    }

    /** @return resource those lines, as the stream the command reads its messages from */
    private static function lines(string $input): mixed
    {
        $stream = self::memory();
        fwrite($stream, $input);
        rewind($stream);

        return $stream;
    }

    /**
     * A turn the agent stood behind, of the thread these cases name.
     *
     * @throws InvalidArgumentException
     */
    private static function finishedTurn(): CompletedTurn
    {
        return new CompletedTurn(self::workspace(), new Completed('hello'));
    }

    /**
     * A turn whose agent stopped before saying it was done: the reader saw the reply, and nothing
     * said why it ended, which is what a {@see FailedTurn} with no error is.
     *
     * @throws InvalidArgumentException
     */
    private static function unfinishedTurn(): FailedTurn
    {
        return new FailedTurn(self::workspace(), new Failed('hello', ''));
    }

    /**
     * The thread these cases name, with the session it continued and the directory it ran in.
     *
     * @throws InvalidArgumentException
     */
    private static function workspace(): ThreadWorkspace
    {
        return new ThreadWorkspace(new ThreadId(self::THREAD), 'session', '/tmp');
    }
}
