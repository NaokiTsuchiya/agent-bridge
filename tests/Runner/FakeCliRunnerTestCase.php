<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use NaokiTsuchiya\AgentBridge\FakeClaude\FakeHome;
use NaokiTsuchiya\AgentBridge\FakeClaude\SessionStore;
use NaokiTsuchiya\AgentBridge\Support\Json;
use NaokiTsuchiya\AgentBridge\Support\TempDir;
use NaokiTsuchiya\AgentBridge\Thread\ThreadDerivation;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;
use PHPUnit\Framework\TestCase;
use Swoole\Runtime;

use function chmod;
use function count;
use function file_get_contents;
use function file_put_contents;
use function json_encode;
use function putenv;
use function realpath;

/**
 * The scaffolding shared by every case that drives a runner against the fake `claude` CLI: a
 * `FAKE_CLAUDE_HOME` and a working directory of its own, and the handful of things every such
 * case ends up asking of them.
 *
 * What is deliberately not here is a test method: {@see SpawnCliRunnerTest} and
 * {@see PersistentCliRunnerTest} assert about processes, not replies, and the two are not the
 * same assertions wearing a different binary — only the setup they start from is.
 *
 *
 * @mago-expect lint:too-many-methods
 * @internal
 */
abstract class FakeCliRunnerTestCase extends TestCase
{
    /** Swoole's hook flags as they were before this class ran. */
    private static int $hookFlags = 0;

    /** {@inheritDoc} */
    #[Override]
    public static function setUpBeforeClass(): void
    {
        self::$hookFlags = Runtime::getHookFlags();
    }

    /** {@inheritDoc} */
    #[Override]
    public static function tearDownAfterClass(): void
    {
        Runtime::setHookFlags(self::$hookFlags);
    }

    /** Where the fake keeps this case's sessions and recordings. */
    protected string $home = '';

    /** The directory the children are started in, resolved because the fake keys sessions by it. */
    protected string $cwd = '';

    /** @return string names this case's temp directories, so a stray one is easy to place */
    abstract protected function homePrefix(): string;

    /** A home and a working directory of this case's own, and the fake pointed at them. */
    #[Override]
    protected function setUp(): void
    {
        $this->home = TempDir::make("{$this->homePrefix()}-home");
        $cwd = realpath(TempDir::make("{$this->homePrefix()}-cwd"));
        // macOS hands a process /private/var where the test saw /var, and the fake keys its
        // sessions by sha1(getcwd()); seeding one under the unresolved path would never match.
        self::assertIsString($cwd);
        $this->cwd = $cwd;

        FakeCliHome::activate($this->home);
    }

    /** The environment is process-wide, so it is put back the way it was found. */
    #[Override]
    protected function tearDown(): void
    {
        FakeCliHome::deactivate();
        TempDir::remove($this->home);
        TempDir::remove($this->cwd);
    }

    /** Puts the thread's derived session in place, so that the first `--resume` finds it. */
    protected function seedSession(ThreadId $thread): void
    {
        $store = new SessionStore(FakeHome::fromEnvironment(), $this->cwd);
        $store->create(ThreadDerivation::sessionId($thread));
    }

    /** @param array<string, mixed> $specification what the fake should do, turn by turn */
    protected function useScenario(array $specification): void
    {
        $path = "{$this->home}/scenario.json";
        $json = json_encode($specification);
        self::assertIsString($json);
        file_put_contents($path, $json);
        putenv("FAKE_CLAUDE_SCENARIO={$path}");
    }

    /**
     * A `claude`-shaped binary that records every start and refuses the first two.
     *
     * The trailing `cat > /dev/null` only matters to the resident runner: its process is written
     * to across turns and kept until {@see PersistentCliRunner::close()} lets it go
     * ({@see PersistentCliRunner::send()}), so a binary that exited right after answering would
     * look, to the pool, exactly like one that had died — discarded instead of reused. The spawn
     * runner closes stdin at once ({@see SpawnCliRunner}), so the same line there only meets an
     * already-closed pipe and returns at once.
     *
     * @param string $counter the file it appends one line to per start
     *
     * @return string the path to the binary
     */
    protected function countingBinary(string $counter): string
    {
        $path = "{$this->home}/counting-claude";
        file_put_contents($path, <<<SH
            #!/bin/sh
            printf '%s\\n' "\$*" >> '{$counter}'
            if [ "\$(wc -l < '{$counter}' | tr -d ' ')" -le 2 ]; then exit 1; fi
            printf '{"type":"result","subtype":"success","is_error":false,"session_id":"x","result":"late"}\\n'
            cat > /dev/null
            SH);
        chmod($path, permissions: 0o755);

        return $path;
    }

    /** @return FakeCliRecords what the fake wrote down about this case */
    protected function records(): FakeCliRecords
    {
        return new FakeCliRecords($this->home);
    }

    /** @return int the pid of the last child the fake recorded */
    protected function lastPid(): int
    {
        $starts = $this->records()->starts();
        $pid = Json::integer($starts[count($starts) - 1] ?? [], 'pid');
        self::assertIsInt($pid, 'No child was recorded.');

        return $pid;
    }

    /** @return string the file's contents, asserted to be readable */
    protected static function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, "Could not read {$path}.");

        return $contents;
    }
}
