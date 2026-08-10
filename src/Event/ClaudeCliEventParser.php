<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Event;

use function array_filter;
use function array_values;
use function is_array;
use function is_bool;
use function is_string;
use function json_decode;

/**
 * Normalizes one line of `claude --output-format stream-json` into {@see AgentEvent}s.
 *
 * A line is never trusted: it may be truncated by a pipe, may be a warning the CLI wrote to the
 * same stream, and its shape changes with the CLI version. Anything this parser cannot read as a
 * known event yields zero events instead of an exception, so that a single odd line cannot take
 * down a long-running session.
 *
 * Two events are deliberately never produced here — see {@see ToolCompleted} and {@see AgentError}.
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

        return match (self::text($decoded, 'type')) {
            'stream_event' => $this->textDeltas($decoded),
            'assistant' => $this->toolStarts($decoded),
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
        $text = self::text($delta, 'type') === 'text_delta' ? self::text($delta, 'text') : null;

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
            $name = self::text($block, 'name');
            $id = self::text($block, 'id');
            if (self::text($block, 'type') !== 'tool_use' || $name === null || $id === null) {
                continue;
            }

            $events[] = new ToolStarted($name, $id);
        }

        return $events;
    }

    /** @param array<array-key, mixed> $line */
    private function turnCompleted(array $line): TurnCompleted
    {
        // Only an explicit `is_error: false` counts as success: a result line whose outcome cannot
        // be read must not reach the consumer as a turn that went well.
        $isError = self::flag($line, 'is_error');

        return new TurnCompleted($isError !== null && !$isError, self::text($line, 'session_id') ?? '');
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
    private static function text(array $node, string $key): ?string
    {
        return is_string($node[$key] ?? null) ? $node[$key] : null;
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
