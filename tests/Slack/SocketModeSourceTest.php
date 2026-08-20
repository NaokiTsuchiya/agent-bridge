<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Swoole\Table;

use function dirname;
use function file_get_contents;
use function preg_match;
use function str_contains;

/**
 * Two promises about the source itself, and one about the runbook that stands in for a live test.
 *
 * They are asserted here rather than left to a reviewer because both are the kind of thing that
 * comes back the moment someone reaches for the obvious shortcut.
 *
 * @internal
 */
final class SocketModeSourceTest extends TestCase
{
    /**
     * `Swoole\Table` shares memory between processes, and there is only one process here; its fixed
     * column widths would be all cost and no benefit (`docs/poc-design.md` 4.2).
     */
    #[Test]
    public function keepsSwooleTableOutOfTheSource(): void
    {
        foreach (self::sourceFiles() as $path => $contents) {
            self::assertFalse(str_contains($contents, Table::class), "{$path} uses the shared-memory table.");
        }
    }

    /**
     * No token in the source. The prefix on its own is fine — it is validated and named in error
     * messages — so what is looked for is a prefix followed by something long enough to be real.
     */
    #[Test]
    public function keepsTokensOutOfTheSource(): void
    {
        foreach (self::sourceFiles() as $path => $contents) {
            self::assertSame(
                0,
                preg_match('/xapp-[0-9A-Za-z-]{10,}|xoxb-[0-9A-Za-z-]{10,}/', $contents),
                "{$path} looks like it carries a token.",
            );
        }
    }

    /**
     * The smoke test is run by a person against a real workspace; what is checked here is that the
     * runbook they follow still says all of the things it has to.
     */
    #[DataProvider('requiredRunbookSteps')]
    #[Test]
    public function documentsEveryManualSmokeStep(string $step): void
    {
        $runbook = file_get_contents(dirname(__DIR__, levels: 2) . '/docs/slack-socket-mode.md');
        self::assertIsString($runbook, 'docs/slack-socket-mode.md is missing.');

        self::assertStringContainsString($step, $runbook);
    }

    /** @return iterable<string, array{string}> */
    public static function requiredRunbookSteps(): iterable
    {
        yield 'enabling Socket Mode' => ['## 1. Socket Mode を有効にする'];
        yield 'creating the app-level token' => ['## 2. App-level token を作る'];
        yield 'the scope it needs' => ['connections:write'];
        yield 'connecting to a real workspace' => ['## 4. 実ワークスペースへ接続する'];
        yield 'seeing an event acknowledged' => ['## 5. イベントの到達と ack を確かめる'];
        yield 'reconnecting after a manual disconnect' => ['## 6. 手動で切断して再接続を確かめる'];
    }

    /**
     * Every PHP file under `src/`, keyed by path.
     *
     * @return iterable<string, string>
     */
    private static function sourceFiles(): iterable
    {
        $root = dirname(__DIR__, levels: 2) . '/src';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            $extension = $file->getExtension();

            if ($extension !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $contents = file_get_contents($path);
            self::assertIsString($contents, "{$path} cannot be read.");

            yield $path => $contents;
        }
    }
}
