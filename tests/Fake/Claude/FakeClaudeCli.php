<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Fake\Claude;

use NaokiTsuchiya\AgentBridge\Tests\Support\Json;

use function array_filter;
use function count;
use function fgets;
use function fwrite;
use function getcwd;
use function getenv;
use function is_array;
use function is_string;
use function json_decode;
use function md5;
use function sleep;
use function substr;
use function uniqid;
use function usleep;

use const STDERR;
use const STDIN;
use const STDOUT;

/**
 * A stand-in for `claude` that speaks the same wire protocol, without login, network or billing.
 *
 * The spec it reproduces is the real CLI, not this file: when the two disagree, the contract tests
 * fail on the real side and the fix belongs here. See docs/fake-claude-cli.md.
 *
 * Unknown flags are swallowed rather than rejected, because the real binary accepts far more of
 * them than this project passes and gains more with every release; a stand-in that refused one
 * would fail on command lines the real thing accepts. {@see FakeArgs} carries the consequence.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final class FakeClaudeCli
{
    /** Counts the turns of this process, which is what a scenario addresses its directives to. */
    private int $turn = 0;

    /** @mago-expect lint:excessive-parameter-list */
    private function __construct(
        private readonly FakeArgs $args,
        private readonly Scenario $scenario,
        private readonly SessionStore $sessions,
        private readonly Recorder $recorder,
        private readonly StreamJsonWriter $writer,
        private readonly string $sessionId,
    ) {}

    /** @param list<string> $argv the process argv, including the script name at index 0 */
    public static function main(array $argv): int
    {
        $cwd = getcwd();
        if ($cwd === false) {
            fwrite(STDERR, data: "fake claude: cannot determine the current directory\n");

            return 2;
        }

        $home = FakeHome::fromEnvironment();
        $recorder = new Recorder($home);
        $recorder->invocation($argv, $cwd);

        $scenario = self::scenario();
        if ($scenario === null) {
            return 2;
        }

        $args = FakeArgs::parse($argv);
        if ($args->wantsVersion) {
            // Answered because the integration group guards itself with `claude --version`, and
            // that guard has to be able to run against whichever binary the environment selected.
            fwrite(STDOUT, data: "0.0.0 (Fake Claude Code)\n");

            return 0;
        }

        $sessionId = $args->resumeId ?? $args->sessionId ?? self::uuid();
        $cli = new self(
            $args,
            $scenario,
            new SessionStore($home, $cwd),
            $recorder,
            new StreamJsonWriter($sessionId, $cwd, $args->includePartialMessages),
            $sessionId,
        );

        return $cli->run();
    }

    /** @return Scenario|null null once the reason has been written to stderr */
    private static function scenario(): ?Scenario
    {
        $path = getenv('FAKE_CLAUDE_SCENARIO');
        if (!is_string($path) || $path === '') {
            return Scenario::empty();
        }

        $scenario = Scenario::fromFile($path);
        if ($scenario === null) {
            fwrite(STDERR, "fake claude: cannot read scenario file: {$path}\n");
        }

        return $scenario;
    }

    /** @return int the process exit code */
    private function run(): int
    {
        $rejection = $this->rejectSession();
        if ($rejection !== null) {
            return $rejection;
        }

        if ($this->args->inputFormat === 'stream-json') {
            return $this->serveStdin();
        }

        $this->runTurn($this->args->prompt);

        return 0;
    }

    /** @return int|null the exit code when the session cannot be served, null when it can */
    private function rejectSession(): ?int
    {
        $resumeId = $this->args->resumeId;
        if ($resumeId !== null) {
            $resumable = $this->sessions->exists($resumeId);
            if ($resumable) {
                return null;
            }

            // Answered on stdout as well as stderr, and before stdin is read at all: the real CLI
            // ends the process here, so a resident caller learns that the session is gone from a
            // result line instead of from a turn that never completes.
            fwrite(STDERR, "No conversation found with session ID: {$resumeId}\n");
            $this->writer->result('', isError: true, turns: 0);

            return 1;
        }

        $sessionId = $this->args->sessionId;
        $taken = $sessionId !== null && $this->sessions->exists($sessionId);
        if ($taken) {
            fwrite(STDERR, "Error: Session ID {$sessionId} is already in use.\n");

            return 1;
        }

        $this->sessions->create($this->sessionId);

        return null;
    }

    /** Reads one turn per stdin line until end of input, which is the caller's way to say stop. */
    private function serveStdin(): int
    {
        while (true) {
            $line = fgets(STDIN);
            if ($line === false) {
                return 0;
            }

            $this->recorder->stdin($line);
            $text = self::textOf($line);
            if ($text === null) {
                continue;
            }

            $exitCode = $this->runTurn($text);
            if ($exitCode !== null) {
                return $exitCode;
            }
        }
    }

    /** @return int|null the exit code when the scenario ends the process mid-turn, null otherwise */
    private function runTurn(string $text): ?int
    {
        $this->turn++;
        $this->recorder->turn($this->sessionId, $this->turn, 'start');
        $directive = $this->scenario->forTurn($this->turn);

        if ($directive->hangs) {
            sleep(3_600);
        }

        usleep($directive->delayMs * 1_000);

        $reply = $directive->text ?? $this->defaultReply($text);
        $this->sessions->append($this->sessionId, $text);

        $this->writer->init();
        $this->writer->partialMessages($reply);
        $this->writer->toolUse($directive->tool);
        $this->writer->assistantText($reply);

        if ($directive->crashCode !== null) {
            return $directive->crashCode;
        }

        $this->writer->result($reply, $directive->isError, $this->turn);
        $this->recorder->turn($this->sessionId, $this->turn, 'end');

        return null;
    }

    /**
     * The reply when no scenario says otherwise: this turn's input, and the one before it.
     *
     * Both halves are read by tests. The first makes a reply traceable to the input that caused
     * it; the second is how a fake can show that context survived, so that the keyword assertion
     * a contract test makes against the real CLI ("it still knows the word from turn 1") passes
     * here for the same reason rather than by luck.
     */
    private function defaultReply(string $text): string
    {
        $history = $this->sessions->history($this->sessionId);
        $previous = $history[count($history) - 1] ?? null;
        $reply = "fake reply to: {$text}";

        return $previous === null ? $reply : "{$reply} | previous input: {$previous}";
    }

    /** @return string|null null for a line that carries no user text, which is not a turn */
    private static function textOf(string $line): ?string
    {
        /** @var array<array-key, mixed>|bool|float|int|string|null $decoded */
        $decoded = json_decode($line, associative: true);
        if (!is_array($decoded)) {
            return null;
        }

        $content = Json::node(Json::node($decoded, 'message'), 'content');
        $text = '';
        foreach (array_filter($content, is_array(...)) as $block) {
            $text .= Json::text($block, 'text') ?? '';
        }

        return $text === '' ? null : $text;
    }

    /** A v4-shaped identifier, for the case where the caller named no session at all. */
    private static function uuid(): string
    {
        $hex = md5(uniqid('', more_entropy: true));
        $a = substr($hex, offset: 0, length: 8);
        $b = substr($hex, offset: 8, length: 4);
        $c = substr($hex, offset: 13, length: 3);
        $d = substr($hex, offset: 17, length: 3);
        $e = substr($hex, offset: 20, length: 12);

        return "{$a}-{$b}-4{$c}-8{$d}-{$e}";
    }
}
