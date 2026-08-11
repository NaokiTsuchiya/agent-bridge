<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Fake\Claude;

use NaokiTsuchiya\AgentBridge\Tests\Support\Json;

use function file_get_contents;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;

/**
 * What a test wants the fake to do, turn by turn.
 *
 * The file is JSON: `{"default": {...}, "turns": {"1": {...}, "2": {...}}}`, where a turn's own
 * entry wins over `default` key by key. Turn numbers are 1-based and count the turns of one
 * process, not of the session. The directives themselves are described on {@see TurnDirective}.
 */
final readonly class Scenario
{
    /**
     * @param array<array-key, mixed> $default
     * @param array<array-key, mixed> $turns
     */
    private function __construct(
        private array $default,
        private array $turns,
    ) {}

    /** The plain fake: every turn behaves the same way. */
    public static function empty(): self
    {
        return new self([], []);
    }

    /** @return self|null null when the path cannot be read or does not hold a JSON object */
    public static function fromFile(string $path): ?self
    {
        $exists = is_file($path);
        $raw = $exists ? file_get_contents($path) : false;
        if (!is_string($raw)) {
            return null;
        }

        /** @var array<array-key, mixed>|bool|float|int|string|null $decoded */
        $decoded = json_decode($raw, associative: true);
        if (!is_array($decoded)) {
            return null;
        }

        return new self(Json::node($decoded, 'default'), Json::node($decoded, 'turns'));
    }

    /** @param int $turn 1-based, counting the turns of this process */
    public function forTurn(int $turn): TurnDirective
    {
        return TurnDirective::fromArray([...$this->default, ...Json::node($this->turns, (string) $turn)]);
    }
}
