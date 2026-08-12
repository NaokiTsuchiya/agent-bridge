<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Slack;

use NaokiTsuchiya\AgentBridge\Di\SlackContext;
use NaokiTsuchiya\AgentBridge\Slack\SlackCommand;
use NaokiTsuchiya\AgentBridge\Tests\Support\TempDir;
use NaokiTsuchiya\RayDiContext\ContextProviderInterface;
use NaokiTsuchiya\RayDiContext\Exception\ExceptionInterface;
use NaokiTsuchiya\RayDiContext\MapContextProvider;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fopen;
use function rewind;
use function str_contains;
use function stream_get_contents;

/**
 * What the command line of the Slack front end takes, and what it does with it.
 *
 * Where the compiled scripts are read from is an argument rather than an environment variable: the
 * tokens are secrets and belong in the environment, while a path is neither secret nor the same for
 * two deployments on one machine. What is pinned here is that the argument is actually the
 * directory the process looks in — a command that read one and used another would still start, in
 * the wrong place.
 *
 * @mago-expect lint:too-many-methods
 */
final class SlackCommandTest extends TestCase
{
    /**
     * Where a case's refusal is written.
     *
     * @var resource|null
     */
    private mixed $errors = null;

    /** An app dir with nothing compiled in it, so that starting always stops at the same point. */
    private string $empty = '';

    /** {@inheritDoc} */
    #[Override]
    protected function setUp(): void
    {
        $errors = fopen('php://memory', mode: 'w+b');
        self::assertIsResource($errors);
        $this->errors = $errors;
        $this->empty = TempDir::make('slack-command');
    }

    /** {@inheritDoc} */
    #[Override]
    protected function tearDown(): void
    {
        TempDir::remove($this->empty);
    }

    /** The directory named on the command line is the one the process looks in. */
    #[Test]
    public function readsTheCompiledScriptsFromTheDirectoryItWasGiven(): void
    {
        $elsewhere = TempDir::make('slack-command-elsewhere');

        $code = $this->command($elsewhere)->run(['agent-bridge-slack', $this->empty]);

        TempDir::remove($elsewhere);
        self::assertSame(3, $code);
        self::assertStringContainsString($this->empty, $this->written());
        self::assertFalse(str_contains($this->written(), $elsewhere), 'It looked where it was installed.');
    }

    /** Without an argument it looks where it was installed. */
    #[Test]
    public function fallsBackToWhereItWasInstalled(): void
    {
        $code = $this->command($this->empty)->run(['agent-bridge-slack']);

        self::assertSame(3, $code);
        self::assertStringContainsString($this->empty, $this->written());
    }

    /** An empty argument is not a directory; it means the same as not passing one. */
    #[Test]
    public function treatsAnEmptyArgumentAsNone(): void
    {
        $code = $this->command($this->empty)->run(['agent-bridge-slack', '']);

        self::assertSame(3, $code);
        self::assertStringContainsString($this->empty, $this->written());
    }

    /** A second argument is somebody expecting this to take something it does not. */
    #[Test]
    public function refusesMoreThanOneArgument(): void
    {
        $code = $this->command($this->empty)->run(['agent-bridge-slack', $this->empty, 'extra']);

        self::assertSame(2, $code);
        self::assertStringContainsString('usage: agent-bridge-slack [APP_DIR]', $this->written());
    }

    /** The usage says which of the two kinds of setting goes where. */
    #[Test]
    public function saysWhichSettingsAreSecrets(): void
    {
        $this->command($this->empty)->run(['agent-bridge-slack', 'a', 'b']);

        $usage = $this->written();
        self::assertStringContainsString('APP_DIR is where the compiled DI scripts are read from', $usage);
        self::assertStringContainsString('SLACK_APP_TOKEN, SLACK_BOT_TOKEN and SLACK_BOT_USER_ID', $usage);
        self::assertStringContainsString('environment', $usage);
    }

    /** @param string $projectRoot where the command was installed */
    private function command(string $projectRoot): SlackCommand
    {
        return new SlackCommand($this->contexts(), $projectRoot, $this->errors());
    }

    /** @return ContextProviderInterface the one mapping bootstrap.php also returns */
    private static function contexts(): ContextProviderInterface
    {
        try {
            return new MapContextProvider([SlackContext::NAME => SlackContext::class]);
        } catch (ExceptionInterface $impossible) {
            // The class named right there exists and is a context; caught rather than declared so
            // that every case above does not have to carry the tag.
            self::fail($impossible->getMessage());
        }
    }

    /** @return string everything the command wrote to its error stream */
    private function written(): string
    {
        $errors = $this->errors();
        rewind($errors);
        $written = stream_get_contents($errors);
        self::assertIsString($written);

        return $written;
    }

    /** @return resource the stream this case's refusals are written to */
    private function errors(): mixed
    {
        $errors = $this->errors;
        self::assertIsResource($errors);

        return $errors;
    }
}
