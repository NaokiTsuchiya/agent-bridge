<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge;

use NaokiTsuchiya\AgentBridge\Integration\ClaudeCliTest;
use NaokiTsuchiya\AgentBridge\Runner\ProcessEnduranceTest;
use NaokiTsuchiya\AgentBridge\Support\Json;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;

use function dirname;
use function escapeshellarg;
use function exec;
use function explode;
use function file_get_contents;
use function implode;
use function preg_quote;
use function sort;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * The endurance group, which only holds together because two files agree.
 *
 * A group costs nothing to declare and nothing to mistype, and every way it breaks leaves the build
 * green: an attribute nobody added leaves the slowest case in the unit run, a group name spelled one
 * way in the attribute and another in the filter excludes nothing, a filter that drops one of the
 * two excluded groups lets the other back in, and an aggregate that forgot the new script leaves a
 * group no local command runs. So the filters are not compared as strings but handed to PHPUnit as
 * written, and what it then selects is compared against the classes the attributes are on.
 *
 * That the coverage run keeps no filter at all — which is what makes CI run this group — is
 * asserted by {@see CoverageReportingTest}.
 */
final class EnduranceGroupTest extends TestCase
{
    /** The class whose only purpose here is to be in no group at all. */
    private const string UNGROUPED = AgentBridgeTest::class;

    /**
     * The whole point of the change: the slowest case in the suite is not in the fast run.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function theUnitRunLeavesTheEnduranceGroupOut(): void
    {
        self::assertFalse(
            self::selects('test:unit', ProcessEnduranceTest::class),
            'The endurance group is in the unit run.',
        );
    }

    /**
     * The exclusion that was already there, which a rewritten filter is exactly where to lose.
     *
     * `--exclude-group` takes one group per occurrence: PHPUnit 13.3 reads `a,b` as the name of a
     * single group called `a,b` and excludes neither. Measured, not inferred — the comma form ran
     * all 797 cases of this suite.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function theUnitRunStillLeavesTheIntegrationGroupOut(): void
    {
        self::assertFalse(
            self::selects('test:unit', ClaudeCliTest::class),
            'The integration group is in the unit run.',
        );
    }

    /**
     * An exclusion wide enough to catch an ungrouped case would empty the suite quietly.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function theUnitRunStillPicksUpUngroupedCases(): void
    {
        self::assertTrue(self::selects('test:unit', self::UNGROUPED), 'The unit run picks up nothing.');
    }

    /**
     * Every endurance case and nothing besides, so neither half of the pair can drift alone.
     *
     * Listed rather than run, which is sound only because this script's filter is `--group`: that
     * one `--list-tests` honours, while `--exclude-group` it ignores outright (see {@see selects()}).
     *
     * @throws ReflectionException
     */
    #[Test]
    public function theEnduranceRunIsExactlyTheGroupedClass(): void
    {
        $expected = self::casesOf(ProcessEnduranceTest::class);
        self::assertNotEmpty($expected);

        $listed = [];
        foreach (explode("\n", self::outputOf('test:endurance', ['--list-tests'])) as $printed) {
            if (!str_starts_with($printed, ' - ')) {
                continue;
            }

            $listed[] = substr($printed, offset: 3);
        }

        sort($expected);
        sort($listed);

        self::assertSame($expected, $listed);
    }

    /**
     * The script has to select the group rather than merely include it.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function theEnduranceRunLeavesEverythingElseOut(): void
    {
        self::assertFalse(
            self::selects('test:endurance', self::UNGROUPED),
            'The endurance run is not confined to its group.',
        );
    }

    /** The aggregate is the local "run everything"; a group left out of it has no local runner. */
    #[Test]
    public function theAggregateRunsTheEnduranceScript(): void
    {
        $composer = Json::decode((string) file_get_contents(dirname(__DIR__) . '/composer.json')) ?? [];

        self::assertContains('@test:endurance', Json::node(Json::node($composer, 'scripts'), 'test'));
    }

    /**
     * Whether the script, run as written, would run the class's first case.
     *
     * A run rather than `--list-tests`: **`--list-tests` ignores group exclusions** (it lists all
     * 797 cases of this suite whatever `--exclude-group` says), so a listing would agree with any
     * exclusion at all. `--filter` narrows to one case and does compose with the group filters, so
     * a case the script excludes leaves nothing to execute and costs nothing to ask about.
     *
     * @param string       $script the name of a `phpunit` script in composer.json
     * @param class-string $class  the case class being asked about
     *
     * @throws ReflectionException
     */
    private static function selects(string $script, string $class): bool
    {
        $case = self::casesOf($class)[0] ?? null;
        self::assertIsString($case, "{$class} has no cases to ask about.");

        // Anchored and quoted: `--filter` is a regular expression, so the backslashes of a
        // namespaced class name are escapes until they are escaped, and an unanchored name also
        // matches every case whose name merely starts with it.
        $quoted = preg_quote($case, delimiter: '/');
        $pattern = "/^{$quoted}\$/";

        return !str_contains(self::outputOf($script, ['--filter', escapeshellarg($pattern)]), 'No tests executed!');
    }

    /**
     * Runs one of this repository's test scripts with the arguments it is written with.
     *
     * The arguments come out of composer.json rather than being repeated here: a filter this file
     * spelled for itself would agree with itself while disagreeing with what anyone runs.
     *
     * @param string       $script the name of a `phpunit` script in composer.json
     * @param list<string> $extra  arguments appended to the script's own, already escaped
     *
     * @return string the combined output
     */
    private static function outputOf(string $script, array $extra): string
    {
        $root = dirname(__DIR__);
        $composer = Json::decode((string) file_get_contents("{$root}/composer.json")) ?? [];
        $line = Json::text(Json::node($composer, 'scripts'), $script);
        self::assertIsString($line, "composer.json has no {$script} script.");
        self::assertTrue(str_starts_with($line, 'phpunit '), "{$script} does not run phpunit.");

        $command = implode(' ', [
            'cd',
            escapeshellarg($root),
            '&&',
            'php',
            escapeshellarg("{$root}/vendor/bin/phpunit"),
            substr($line, offset: strlen('phpunit ')),
            ...$extra,
        ]);

        $output = [];
        $exitCode = 0;
        exec("{$command} 2>&1", $output, $exitCode);
        $reported = implode("\n", $output);
        self::assertSame(0, $exitCode, message: "Running {$script} failed: {$reported}");

        return $reported;
    }

    /**
     * @param class-string $class the case class to enumerate
     *
     * @return list<string> the fully qualified `Class::method` of every case in the class
     *
     * @throws ReflectionException
     */
    private static function casesOf(string $class): array
    {
        $cases = [];
        foreach (new ReflectionClass($class)->getMethods() as $method) {
            if ($method->getAttributes(Test::class) === []) {
                continue;
            }

            $cases[] = "{$class}::{$method->getName()}";
        }

        return $cases;
    }
}
