<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Integration;

use NaokiTsuchiya\AgentBridge\Cli\CliCommand;
use NaokiTsuchiya\AgentBridge\Di\BaseRepositoryProvider;
use NaokiTsuchiya\AgentBridge\Tests\Di\CompiledServe;
use NaokiTsuchiya\AgentBridge\Tests\Support\ClaudeBinary;
use NaokiTsuchiya\AgentBridge\Tests\Support\CliProcess;
use NaokiTsuchiya\AgentBridge\Tests\Support\ExecutablePath;
use NaokiTsuchiya\AgentBridge\Tests\Support\GitRepository;
use NaokiTsuchiya\AgentBridge\Tests\Support\TempDir;
use NaokiTsuchiya\RayDiContext\Exception\InvalidAppMeta;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function implode;
use function str_contains;
use function strtolower;
use function uniqid;

use const PHP_BINARY;

/**
 * One round trip against the real binary, driven the way a person would drive it.
 *
 * Everything else about the front end is settled in the unit group against the fake; what is left
 * for this group is the one thing a stand-in cannot show — that a thread named on the command line
 * reaches an actual Claude Code, in a worktree cut for it, and comes back with an answer.
 *
 * No exact wording is asserted: the real CLI answers differently every time. The keyword is asked
 * for in the prompt, which is also why this passes when the group is pointed at the fake — it
 * echoes the prompt back.
 */
#[Group('integration')]
final class CliSmokeTest extends TestCase
{
    /** How long the real binary is given for one turn. */
    private const float PATIENCE = 180.0;

    /** The repository the worktree is cut from. */
    private string $repository = '';

    /** Where `claude` is found while the case runs. */
    private string $bin = '';

    /** {@inheritDoc} */
    #[Override]
    protected function setUp(): void
    {
        $this->repository = GitRepository::make('cli-smoke-repo');
        $this->bin = TempDir::make('cli-smoke-bin');
    }

    /** {@inheritDoc} */
    #[Override]
    protected function tearDown(): void
    {
        TempDir::remove($this->bin);
        GitRepository::remove($this->repository);
    }

    /**
     * A thread nobody has used before gets an answer on standard output.
     *
     * @throws InvalidAppMeta
     */
    #[Test]
    public function answersOneTurnAgainstTheRealBinary(): void
    {
        $root = dirname(__DIR__, levels: 2);
        $thread = 'cli:smoke-' . uniqid();
        $process = CliProcess::start([PHP_BINARY, "{$root}/bin/agent-bridge-cli", $thread], $root, [
            'PATH' => ExecutablePath::answering($this->bin, ClaudeBinary::fromEnvironment()),
            BaseRepositoryProvider::VARIABLE => $this->repository,
            CliCommand::APP_DIR => CompiledServe::meta()->appDir,
        ]);

        $process->sendRaw('Reply with exactly one word: pineapple');
        $process->closeStdin();
        $code = $process->waitForExit(self::PATIENCE);
        $answer = implode("\n", $process->lines());
        $errors = $process->stderr();
        $process->stop();

        self::assertSame(0, $code, $errors);
        self::assertTrue(str_contains(strtolower($answer), 'pineapple'), "The reply was: {$answer}");
        self::assertDirectoryExists("{$this->repository}/.worktrees");
    }
}
