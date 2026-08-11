<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Fake\Claude;

use NaokiTsuchiya\AgentBridge\Tests\Support\Json;

/**
 * What one turn of the fake should do, once a {@see Scenario} has merged the defaults in.
 *
 * Every field is optional in the file and every unreadable value falls back to the plain
 * behaviour: a scenario is written by a test to bend one thing, and a typo in it must not turn
 * into a differently-shaped turn that the test then asserts against.
 */
final readonly class TurnDirective
{
    /**
     * One field per directive, so that a turn's behaviour is readable in one place.
     *
     * @mago-expect lint:excessive-parameter-list
     */
    private function __construct(
        public ?string $text,
        public ?ToolDirective $tool,
        public bool $isError,
        /** @var non-negative-int */
        public int $delayMs,
        public ?int $crashCode,
        public bool $hangs,
    ) {}

    /** @param array<array-key, mixed> $merged the turn's own keys over the scenario defaults */
    public static function fromArray(array $merged): self
    {
        $delay = Json::integer($merged, 'delay_ms') ?? 0;

        return new self(
            Json::text($merged, 'text'),
            self::tool($merged),
            Json::flag($merged, 'is_error') === true,
            $delay > 0 ? $delay : 0,
            self::crashCode($merged),
            Json::flag($merged, 'hang') === true,
        );
    }

    /**
     * @param array<array-key, mixed> $merged
     *
     * @return ToolDirective|null null when the turn asks for no tool call
     */
    private static function tool(array $merged): ?ToolDirective
    {
        $tool = Json::node($merged, 'tool');
        if ($tool === []) {
            return null;
        }

        return new ToolDirective(
            Json::text($tool, 'name') ?? 'Bash',
            Json::text($tool, 'id') ?? 'toolu_fake',
            Json::text($tool, 'result') ?? '',
        );
    }

    /**
     * @param array<array-key, mixed> $merged
     *
     * @return int|null the code to die with mid-turn, or null to finish the turn normally
     */
    private static function crashCode(array $merged): ?int
    {
        $code = Json::integer($merged, 'crash');
        if ($code !== null) {
            return $code;
        }

        return Json::flag($merged, 'crash') === true ? 1 : null;
    }
}
