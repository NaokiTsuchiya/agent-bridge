<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use NaokiTsuchiya\AgentBridge\Support\TempDir;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function explode;
use function file_get_contents;
use function ini_restore;
use function ini_set;
use function trim;

/**
 * That a logged line goes wherever the deployment said its log goes.
 *
 * `error_log()` rather than a write to `php://stderr` is the whole of this adapter, and the
 * difference only shows up where a deployment has configured a log of its own: a direct write would
 * land on the terminal of whoever started the process and nowhere a running one can be read from.
 * The configured file is what makes that visible here.
 *
 * @internal
 */
final class StderrSlackLoggerTest extends TestCase
{
    /** Something a connection might have said, distinctive enough to find again. */
    private const string MESSAGE = 'the connection was closed while a turn was being answered';

    /** The directory this case's own log file lives in. */
    private string $directory = '';

    /** A log file per case, so that no case reads a line another one wrote. */
    #[Override]
    protected function setUp(): void
    {
        $this->directory = TempDir::make('stderr-slack-logger');
    }

    /** The log destination is process-wide, so it goes back to php.ini's. */
    #[Override]
    protected function tearDown(): void
    {
        ini_restore('error_log');
        TempDir::remove($this->directory);
    }

    /**
     * One call, one line, in the file the deployment configured.
     *
     * Where `error_log()` writes is an ini setting and nothing else, so pointing it at a file of
     * this case's own is the only way to read back what the adapter wrote; `tearDown()` puts the
     * process' own destination back.
     *
     * @mago-expect lint:no-ini-set
     */
    #[Test]
    public function writesOneLineWhereTheLogIsConfiguredToGo(): void
    {
        $log = "{$this->directory}/error.log";
        self::assertNotFalse(ini_set('error_log', $log), 'The log destination could not be set.');

        new StderrSlackLogger()->log(self::MESSAGE);

        $written = file_get_contents($log);
        self::assertIsString($written, 'Nothing was written where the log was configured to go.');
        $logged = trim($written);

        self::assertStringContainsString('[slack] ' . self::MESSAGE, $logged);
        self::assertCount(1, explode("\n", $logged), "One call wrote more than one line: {$logged}");
    }
}
