<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Fake;

use function array_key_exists;
use function count;
use function explode;
use function implode;
use function in_array;
use function str_contains;
use function str_starts_with;

/**
 * The command line of the fake CLI, read the way the real `claude` reads its own.
 *
 * The real CLI accepts far more flags than this project passes, and gains more with every
 * release. Rejecting an unrecognized flag would make the fake fail on command lines the real
 * binary accepts, which is the one thing a stand-in must never do, so anything not listed here
 * is swallowed. The list below therefore has to name every flag that *takes a value*: an
 * unlisted `--flag value` would leave `value` looking like the prompt.
 */
final readonly class FakeArgs
{
    /** Flags whose value is the next argv entry, rather than a separate `--flag=value` pair. */
    private const array VALUE_FLAGS = [
        '--session-id',
        '--resume',
        '--input-format',
        '--output-format',
        '--allowedTools',
        '--disallowedTools',
        '--model',
        '--permission-mode',
        '--add-dir',
        '--append-system-prompt',
        '--settings',
    ];

    /** @param string $prompt the positional arguments joined, i.e. the one-shot prompt */
    private function __construct(
        public ?string $sessionId,
        public ?string $resumeId,
        public ?string $inputFormat,
        public bool $includePartialMessages,
        public string $prompt,
    ) {}

    /** @param list<string> $argv the raw argv, including the script name at index 0 */
    public static function parse(array $argv): self
    {
        $values = [];
        $positional = [];
        $count = count($argv);
        for ($i = 1; $i < $count; $i++) {
            $arg = $argv[$i] ?? '';
            if (!str_starts_with($arg, '-')) {
                $positional[] = $arg;
                continue;
            }

            if (str_contains($arg, '=')) {
                $pair = explode('=', $arg, limit: 2);
                $values[$pair[0]] = $pair[1] ?? '';
                continue;
            }

            if (in_array($arg, self::VALUE_FLAGS, strict: true)) {
                $values[$arg] = $argv[$i + 1] ?? '';
                $i++;
                continue;
            }

            $values[$arg] = '';
        }

        return new self(
            $values['--session-id'] ?? null,
            $values['--resume'] ?? null,
            $values['--input-format'] ?? null,
            array_key_exists('--include-partial-messages', $values),
            implode(' ', $positional),
        );
    }
}
