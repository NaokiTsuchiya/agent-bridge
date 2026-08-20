<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function implode;

/**
 * The defaults, and the rule that they live in exactly one place.
 *
 * An allow-list written into the runner would be a permission decision buried in an execution
 * detail, where nobody deploying this project would think to look for it.
 */
final class ClaudeCliSettingsTest extends TestCase
{
    /** The binary is `claude` unless someone says otherwise. */
    #[Test]
    public function defaultsToTheClaudeBinaryOnThePath(): void
    {
        self::assertSame('claude', new ClaudeCliSettings()->binary);
    }

    /** Another binary can be put in its place. */
    #[Test]
    public function acceptsAnotherBinary(): void
    {
        self::assertSame('/opt/agent/claude', new ClaudeCliSettings(binary: '/opt/agent/claude')->binary);
    }

    /** The default allow-list exists and can be replaced. */
    #[Test]
    public function carriesTheAllowedToolsAndTakesAnotherList(): void
    {
        self::assertSame(ClaudeCliSettings::READ_ONLY_TOOLS, new ClaudeCliSettings()->allowedTools);
        self::assertNotSame([], ClaudeCliSettings::READ_ONLY_TOOLS);
        self::assertSame(['Bash'], new ClaudeCliSettings(allowedTools: ['Bash'])->allowedTools);
    }

    /** The list is written here, and nowhere in the runner that passes it on. */
    #[Test]
    public function theRunnerSourceHoldsNoToolLiteral(): void
    {
        $runner = self::source('src/Runner/PersistentCliRunner.php');

        foreach (ClaudeCliSettings::READ_ONLY_TOOLS as $tool) {
            self::assertStringNotContainsString("'{$tool}'", $runner);
            self::assertStringNotContainsString("\"{$tool}\"", $runner);
        }

        self::assertStringNotContainsString(implode(',', ClaudeCliSettings::READ_ONLY_TOOLS), $runner);
    }

    /** The one place that does hold it. */
    #[Test]
    public function theSettingsSourceHoldsTheList(): void
    {
        $settings = self::source('src/Runner/ClaudeCliSettings.php');

        foreach (ClaudeCliSettings::READ_ONLY_TOOLS as $tool) {
            self::assertStringContainsString("'{$tool}'", $settings);
        }
    }

    /** @return string the file's contents, read from the repository root */
    private static function source(string $relative): string
    {
        $contents = file_get_contents(dirname(__DIR__, levels: 2) . '/' . $relative);
        self::assertIsString($contents);

        return $contents;
    }
}
