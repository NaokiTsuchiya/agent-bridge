<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests;

use NaokiTsuchiya\AgentBridge\Tests\Support\Json;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function explode;
use function file_get_contents;
use function preg_match;
use function str_contains;
use function substr_count;

/**
 * The coverage setup is spread over four configuration files, and every way it breaks leaves the
 * build green: an exclude pointing at a renamed file silently grows the denominator, a clover path
 * that only one side was updated in uploads nothing, a leg guard naming a PHP version the matrix
 * does not have skips the upload everywhere. These assert the couplings that nothing else would.
 */
final class CoverageReportingTest extends TestCase
{
    /**
     * Excluding a path that no longer exists is not an error to PHPUnit — it just measures the file
     * that took its place, and the number moves for a reason no one can see in the diff.
     */
    #[Test]
    public function excludesTheSwooleTransportByPathsThatExist(): void
    {
        $exclude = self::capture('#<exclude>(.*?)</exclude>#s', self::read('phpunit.xml.dist'));

        $excluded = [];
        foreach (explode('<file>', $exclude) as $tail) {
            if (!str_contains($tail, needle: '</file>')) {
                continue;
            }

            $path = self::capture('#^([^<]+)</file>#', $tail);
            $excluded[] = $path;
            self::assertFileExists(self::root() . "/{$path}");
        }

        foreach ([
            'src/Slack/SwooleHttpClientFactory.php',
            'src/Slack/SwooleSlackApiClient.php',
            'src/Slack/SwooleSocketModeConnection.php',
            'src/Slack/SwooleSocketModeConnector.php',
        ] as $transport) {
            self::assertContains($transport, $excluded);
        }
    }

    /** The script writes the report and the workflow uploads it; only one of them has to move. */
    #[Test]
    public function writesTheCloverWhereTheWorkflowUploadsItFrom(): void
    {
        $composer = Json::decode(self::read('composer.json')) ?? [];
        $script = Json::text(Json::node($composer, 'scripts'), 'test:coverage');
        self::assertIsString($script);

        $clover = self::capture('#--coverage-clover=(\S+)#', $script);

        self::assertStringContainsString("files: {$clover}", self::read('.github/workflows/ci.yml'));
    }

    /** Without a driver the coverage run produces an empty report and still exits 0. */
    #[Test]
    public function asksTheRunnerForACoverageDriver(): void
    {
        $workflow = self::read('.github/workflows/ci.yml');

        self::assertStringContainsString('coverage: pcov', $workflow);
        self::assertStringNotContainsString('coverage: none', $workflow);
    }

    /** Tokenless upload is what a public repository gets; a token here would be a secret to keep. */
    #[Test]
    public function uploadsWithoutAToken(): void
    {
        $workflow = self::read('.github/workflows/ci.yml');

        self::assertSame(1, substr_count($workflow, needle: 'codecov/codecov-action@'));
        self::assertStringNotContainsString('CODECOV_TOKEN', $workflow);
    }

    /**
     * Both steps have to sit on the same leg — on every leg the same report would be counted twice,
     * and on a version the matrix does not run neither step would happen at all.
     */
    #[Test]
    public function producesAndUploadsOnOneMatrixLegTheBuildActuallyRuns(): void
    {
        $workflow = self::read('.github/workflows/ci.yml');
        $pattern = "#if: matrix.php == '([^']+)'#";

        $producing = self::capture($pattern, self::stepContaining($workflow, 'composer test:coverage'));
        $uploading = self::capture($pattern, self::stepContaining($workflow, 'codecov/codecov-action@'));
        self::assertSame($producing, $uploading);

        self::assertStringContainsString("'{$producing}'", self::capture('#php: \[([^\]]+)\]#', $workflow));
    }

    /** One status left as a gate is enough to fail a pull request for lowering coverage. */
    #[Test]
    public function keepsBothCodecovStatusesInformational(): void
    {
        $codecov = self::read('codecov.yml');

        foreach (['project', 'patch'] as $status) {
            self::assertSame(
                1,
                preg_match("#{$status}:\s+default:\s+informational: true#", $codecov),
                "{$status} status is not informational",
            );
        }
    }

    /** @return string the repository root, where every file this test reads lives */
    private static function root(): string
    {
        return dirname(__DIR__);
    }

    /** @param string $relative a path below the repository root */
    private static function read(string $relative): string
    {
        $contents = file_get_contents(self::root() . "/{$relative}");
        self::assertIsString($contents, "{$relative} is not readable");

        return $contents;
    }

    /**
     * @param string $pattern a regular expression with exactly one capturing group
     *
     * @return string what the group captured
     */
    private static function capture(string $pattern, string $subject): string
    {
        $matches = [];
        self::assertSame(1, preg_match($pattern, $subject, $matches), "{$pattern} matched nothing");

        return $matches[1] ?? '';
    }

    /**
     * @param string $needle something written inside the wanted step
     *
     * @return string the one step of the workflow that contains it
     */
    private static function stepContaining(string $workflow, string $needle): string
    {
        $steps = [];
        foreach (explode("\n      - ", $workflow) as $step) {
            if (!str_contains($step, $needle)) {
                continue;
            }

            $steps[] = $step;
        }

        self::assertCount(1, $steps, "not exactly one step contains {$needle}");

        return $steps[0] ?? '';
    }
}
