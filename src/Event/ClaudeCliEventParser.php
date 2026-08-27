<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Event;

use NaokiTsuchiya\AgentBridge\Json;

use function array_filter;
use function array_values;
use function is_array;
use function is_bool;
use function json_decode;

/**
 * Normalizes one line of `claude --output-format stream-json` into {@see AgentEvent}s.
 *
 * A line is never trusted: it may be truncated by a pipe, may be a warning the CLI wrote to the
 * same stream, and its shape changes with the CLI version. Anything this parser cannot read as a
 * known event yields zero events instead of an exception, so that a single odd line cannot take
 * down a long-running session.
 *
 * One event is deliberately never produced here — see {@see AgentError}.
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class ClaudeCliEventParser
{
    /** @return list<AgentEvent> */
    public function parse(string $line): array
    {
        /** @var array<array-key, mixed>|bool|float|int|string|null $decoded */
        $decoded = json_decode($line, associative: true);
        if (!is_array($decoded)) {
            return [];
        }

        return match (Json::text($decoded, 'type')) {
            'stream_event' => $this->textDeltas($decoded),
            'assistant' => $this->toolStarts($decoded),
            'user' => $this->toolCompletions($decoded),
            'result' => [$this->turnCompleted($decoded)],
            default => [],
        };
    }

    /**
     * @param array<array-key, mixed> $line
     *
     * @return list<AgentEvent>
     */
    private function textDeltas(array $line): array
    {
        $delta = self::node(self::node($line, 'event'), 'delta');
        // text_delta is matched as a whitelist rather than "anything but thinking/signature":
        // input_json_delta also occurs (it streams a tool call's arguments) and has no `text`.
        $text = Json::text($delta, 'type') === 'text_delta' ? Json::text($delta, 'text') : null;

        return $text === null ? [] : [new TextDelta($text)];
    }

    /**
     * @param array<array-key, mixed> $line
     *
     * @return list<AgentEvent>
     */
    private function toolStarts(array $line): array
    {
        // Tool calls are read from the assistant line only. The same call is also announced by a
        // stream_event content_block_start, and reading both would start every tool twice.
        $events = [];
        foreach (self::nodes(self::node($line, 'message'), 'content') as $block) {
            $name = Json::text($block, 'name');
            $id = Json::text($block, 'id');
            if (Json::text($block, 'type') !== 'tool_use' || $name === null || $id === null) {
                continue;
            }

            $events[] = new ToolStarted($name, $id);
        }

        return $events;
    }

    /**
     * @param array<array-key, mixed> $line
     *
     * @return list<AgentEvent>
     */
    private function toolCompletions(array $line): array
    {
        $events = [];
        foreach (self::nodes(self::node($line, 'message'), 'content') as $block) {
            $id = Json::text($block, 'tool_use_id');
            if (Json::text($block, 'type') !== 'tool_result' || $id === null) {
                continue;
            }

            // Same rule as turnCompleted(): only an explicit `is_error: false` counts as success.
            $isError = self::flag($block, 'is_error');
            $events[] = new ToolCompleted($id, $isError !== null && !$isError);
        }

        return $events;
    }

    /** @param array<array-key, mixed> $line */
    private function turnCompleted(array $line): TurnCompleted
    {
        // Only an explicit `is_error: false` counts as success: a result line whose outcome cannot
        // be read must not reach the consumer as a turn that went well.
        $isError = self::flag($line, 'is_error');

        return new TurnCompleted($isError !== null && !$isError, Json::text($line, 'session_id') ?? '');
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     *
     * @pure
     */
    private static function node(array $node, string $key): array
    {
        return is_array($node[$key] ?? null) ? $node[$key] : [];
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return list<array<array-key, mixed>>
     *
     * @pure
     */
    private static function nodes(array $node, string $key): array
    {
        return array_values(array_filter(self::node($node, $key), is_array(...)));
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @pure
     */
    private static function flag(array $node, string $key): ?bool
    {
        return is_bool($node[$key] ?? null) ? $node[$key] : null;
    }
}
