<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Di;

use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function getcwd;
use function getenv;
use function putenv;

/**
 * Where the repository path comes from.
 *
 * A provider rather than a bound value because the value is the running machine's, not the build
 * machine's; the cases below are about the three shapes `getenv` answers with.
 */
final class BaseRepositoryProviderTest extends TestCase
{
    /** What the variable held before the case ran, put back afterwards. */
    private string|false $before = false;

    /** The variable is process-wide, so a case that changed it must hand it back. */
    #[Override]
    protected function setUp(): void
    {
        $this->before = getenv(BaseRepositoryProvider::VARIABLE);
    }

    /** Restores whatever was there, including its absence. */
    #[Override]
    protected function tearDown(): void
    {
        if ($this->before === false) {
            putenv(BaseRepositoryProvider::VARIABLE);

            return;
        }

        putenv(BaseRepositoryProvider::VARIABLE . '=' . $this->before);
    }

    /** The deployment case: the server is told which repository to cut worktrees from. */
    #[Test]
    public function readsTheVariableWhenItIsSet(): void
    {
        putenv(BaseRepositoryProvider::VARIABLE . '=/srv/agent-bridge');

        self::assertSame('/srv/agent-bridge', new BaseRepositoryProvider()->get());
    }

    /** The development case: the server is started in the repository it works on. */
    #[Test]
    public function fallsBackToTheWorkingDirectoryWhenTheVariableIsUnset(): void
    {
        putenv(BaseRepositoryProvider::VARIABLE);

        self::assertSame(getcwd(), new BaseRepositoryProvider()->get());
    }

    /** An empty value looks unset to a person and is a distinct value to `getenv`. */
    #[Test]
    public function fallsBackToTheWorkingDirectoryWhenTheVariableIsEmpty(): void
    {
        // An empty value is what an unset-looking `FOO=` in a unit file or compose file produces,
        // and handing "" on as a repository path would fail much further away from the cause.
        putenv(BaseRepositoryProvider::VARIABLE . '=');

        self::assertSame(getcwd(), new BaseRepositoryProvider()->get());
    }
}
