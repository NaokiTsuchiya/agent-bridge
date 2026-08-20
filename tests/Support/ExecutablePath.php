<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Support;

use PHPUnit\Framework\Assert;

use function exec;
use function str_contains;
use function symlink;

use const PHP_BINARY;

/**
 * A `PATH` built for one case, since that is how a front end chooses its agent.
 *
 * The execution layer starts `claude` by its bare name and lets the operating system find it
 * ({@see \NaokiTsuchiya\AgentBridge\Runner\ClaudeCliSettings}), which is the only seam a test has
 * once the bindings come out of a compiled injector: the settings there are the deployment's, not
 * the test's. Pointing `PATH` at a directory that answers to `claude` is therefore how a whole
 * process — child and all — is aimed at the fake without bending a single production default.
 */
final class ExecutablePath
{
    /** What the agent binary is called, whichever build is behind it. */
    private const string AGENT = 'claude';

    /** Started by bare name by the code under test: `git` by the worktree manager. */
    private const string GIT = 'git';

    /** The fake's shebang is `env php`, so a `PATH` without one cannot run it. */
    private const string PHP = 'php';

    /**
     * What the real `claude` shells out to for a stored session, on the one platform that has it
     * ({@see linkIfPresent}). Not on the platform's `PATH` at all is a fine outcome — the fake
     * never calls it, and the real `claude` in that case would fail its own way, on its own.
     */
    private const string SECURITY = 'security';

    /**
     * @param string $directory an empty directory of the case's own to fill
     * @param string $binary    what `claude` is to resolve to
     *
     * @return string a `PATH` holding that directory and nothing else
     */
    public static function answering(string $directory, string $binary): string
    {
        self::link($directory, self::AGENT, self::resolved($binary));

        return self::onlyThe($directory);
    }

    /**
     * @param string $directory an empty directory of the case's own to fill
     *
     * @return string a `PATH` on which no agent can be found at all
     */
    public static function withoutAnAgent(string $directory): string
    {
        return self::onlyThe($directory);
    }

    /**
     * The directory alone, with everything but the agent put into it.
     *
     * Nothing of the caller's `PATH` is kept: a real `claude` further along it would be found and
     * started, which is the one thing the unit group may never do.
     */
    private static function onlyThe(string $directory): string
    {
        self::link($directory, self::GIT, self::which(self::GIT));
        self::link($directory, self::PHP, PHP_BINARY);
        self::linkIfPresent($directory, self::SECURITY);

        return $directory;
    }

    /** @param string $target what the name in that directory is to point at */
    private static function link(string $directory, string $name, string $target): void
    {
        $linked = symlink($target, "{$directory}/{$name}");
        Assert::assertTrue($linked, "Could not put \"{$name}\" into {$directory}.");
    }

    /** @return string where the tool is, which the machine running this has to have */
    private static function which(string $tool): string
    {
        $path = self::found($tool);
        Assert::assertNotNull($path, "This machine has no \"{$tool}\".");

        return $path;
    }

    /** Puts $name into the directory only when this machine has one to point it at. */
    private static function linkIfPresent(string $directory, string $name): void
    {
        $found = self::found($name);

        if ($found !== null) {
            self::link($directory, $name, $found);
        }
    }

    /** @return string|null where the tool is, or null when this machine has none by that name */
    private static function found(string $tool): ?string
    {
        $output = [];
        $exitCode = 1;
        exec("command -v {$tool} 2>/dev/null", $output, $exitCode);

        $path = $output[0] ?? '';

        return $exitCode === 0 && $path !== '' ? $path : null;
    }

    /**
     * `symlink()` writes whatever string it is given as the link's target, unresolved — a bare
     * command name would become a link that names itself, since the shell only resolves bare
     * names by searching `PATH`, and `symlink()` does not shell out. An absolute path already
     * names one file and is returned as it is; a bare name is looked up first.
     */
    private static function resolved(string $binary): string
    {
        return str_contains($binary, '/') ? $binary : self::which($binary);
    }
}
