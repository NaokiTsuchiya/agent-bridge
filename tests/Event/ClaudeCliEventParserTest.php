<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Event;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_values;
use function file;
use function implode;
use function str_contains;

use const FILE_IGNORE_NEW_LINES;

/**
 * Fixture provenance: `fixtures/observed-turn.jsonl` holds real lines captured from
 * `claude -p --output-format stream-json --verbose --include-partial-messages` on Claude Code
 * 2.1.223 (2026-08-10), spliced from two probe runs into one turn. Two edits were made: every
 * session_id was set to the same value (the runs had one each), and the system/init line — 11884
 * bytes of cwd, plugin list, MCP servers and memory paths — was shortened to the same key set with
 * the local environment taken out.
 *
 * `fixtures/synthetic-result-error.jsonl` is NOT a capture: all three probe runs ended in
 * `subtype: "success"`, so the failing result was built from the shape issue #4 records as
 * measured (`error_during_execution`, `is_error: true`, `num_turns: 0`). It lives in its own file
 * so that it cannot be mistaken for observed data.
 *
 * Lines are selected out of the fixture by a distinctive substring rather than by line number, so
 * that the case names stay readable and a fixture edit fails loudly instead of silently testing a
 * different line.
 *
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final class ClaudeCliEventParserTest extends TestCase
{
    /** The one line shape that carries reply text: everything else in a turn is noise to a chat frontend. */
    #[Test]
    public function textDeltaLineYieldsOneTextDeltaCarryingTheDifference(): void
    {
        self::assertSame(['text:h'], self::parse(self::observedLine('"type":"text_delta"')));
    }

    /** Only text_delta carries reply text; the other delta types must not leak into the stream. */
    #[DataProvider('deltaTypesThatCarryNoText')]
    #[Test]
    public function deltaTypesOtherThanTextDeltaYieldNothing(string $marker): void
    {
        self::assertSame([], self::parse(self::observedLine($marker)));
    }

    /** @return iterable<string, array{string}> */
    public static function deltaTypesThatCarryNoText(): iterable
    {
        yield 'thinking_delta' => ['"type":"thinking_delta"'];
        yield 'signature_delta' => ['"type":"signature_delta"'];
        // Not in the issue's list of three delta types: it streams a tool call's arguments, and
        // carries `partial_json` where a text delta carries `text`.
        yield 'input_json_delta' => ['"type":"input_json_delta"'];
    }

    /** Envelope events around the deltas carry no content of their own. */
    #[DataProvider('streamEventsThatAreNotContentBlockDeltas')]
    #[Test]
    public function streamEventsOtherThanContentBlockDeltaYieldNothing(string $marker): void
    {
        self::assertSame([], self::parse(self::observedLine($marker)));
    }

    /** @return iterable<string, array{string}> */
    public static function streamEventsThatAreNotContentBlockDeltas(): iterable
    {
        yield 'message_start' => ['"event":{"type":"message_start"'];
        yield 'content_block_start of a text block' => ['"content_block":{"type":"text"'];
        // The tool call this one announces is also in the assistant line. Reading both would start
        // the same tool twice, so this line has to stay silent.
        yield 'content_block_start of a tool_use block' => ['"content_block":{"type":"tool_use"'];
        yield 'content_block_stop' => ['"event":{"type":"content_block_stop"'];
        yield 'message_delta' => ['"event":{"type":"message_delta"'];
        yield 'message_stop' => ['"event":{"type":"message_stop"'];
    }

    /** The identifier matters as much as the name: a later tool completion refers back to it. */
    #[Test]
    public function assistantToolUseYieldsToolStartedWithBothNameAndId(): void
    {
        self::assertSame(
            ['tool started:Bash:toolu_01VjzN7p8DfsYF7jjfwxSkV4'],
            self::parse(self::observedLine('"content":[{"type":"tool_use"')),
        );
    }

    /** Reply text and thinking already arrive as deltas, so the assistant line must not duplicate them. */
    #[DataProvider('assistantBlocksThatAreNotToolCalls')]
    #[Test]
    public function assistantLinesWithoutAToolCallYieldNothing(string $marker): void
    {
        self::assertSame([], self::parse(self::observedLine($marker)));
    }

    /** @return iterable<string, array{string}> */
    public static function assistantBlocksThatAreNotToolCalls(): iterable
    {
        yield 'text block' => ['"content":[{"type":"text"'];
        yield 'thinking block' => ['"content":[{"type":"thinking"'];
    }

    /** A tool call missing either half of its identity is unusable, and must not abort the stream. */
    #[DataProvider('malformedAssistantLines')]
    #[Test]
    public function malformedAssistantLinesYieldNothingWithoutThrowing(string $line): void
    {
        self::assertSame([], self::parse($line));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedAssistantLines(): iterable
    {
        yield 'no message' => ['{"type":"assistant"}'];
        yield 'message is not an object' => ['{"type":"assistant","message":"hi"}'];
        yield 'no content' => ['{"type":"assistant","message":{"role":"assistant"}}'];
        yield 'content is not an array' => ['{"type":"assistant","message":{"content":"hi"}}'];
        yield 'block is not an object' => ['{"type":"assistant","message":{"content":["tool_use"]}}'];
        yield 'tool_use without a name' => [
            '{"type":"assistant","message":{"content":[{"type":"tool_use","id":"toolu_1"}]}}',
        ];
        yield 'tool_use without an id' => [
            '{"type":"assistant","message":{"content":[{"type":"tool_use","name":"Bash"}]}}',
        ];
        yield 'tool_use with a non-string name' => [
            '{"type":"assistant","message":{"content":[{"type":"tool_use","name":7,"id":"toolu_1"}]}}',
        ];
        yield 'tool_use with a non-string id' => [
            '{"type":"assistant","message":{"content":[{"type":"tool_use","name":"Bash","id":7}]}}',
        ];
    }

    /** A delta whose text is absent or not a string is unusable, and must not abort the stream. */
    #[DataProvider('malformedStreamEventLines')]
    #[Test]
    public function malformedStreamEventsYieldNothingWithoutThrowing(string $line): void
    {
        self::assertSame([], self::parse($line));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedStreamEventLines(): iterable
    {
        yield 'no event' => ['{"type":"stream_event"}'];
        yield 'event is not an object' => ['{"type":"stream_event","event":"content_block_delta"}'];
        yield 'no delta' => ['{"type":"stream_event","event":{"type":"content_block_delta","index":0}}'];
        yield 'delta is not an object' => [
            '{"type":"stream_event","event":{"type":"content_block_delta","delta":"text_delta"}}',
        ];
        yield 'text_delta without text' => [
            '{"type":"stream_event","event":{"type":"content_block_delta","delta":{"type":"text_delta"}}}',
        ];
        yield 'text_delta with a numeric text' => [
            '{"type":"stream_event","event":{"type":"content_block_delta","delta":{"type":"text_delta","text":7}}}',
        ];
        yield 'text_delta with an array text' => [
            '{"type":"stream_event","event":{"type":"content_block_delta","delta":{"type":"text_delta","text":["h"]}}}',
        ];
    }

    /** The session id is what lets the next turn resume the same conversation. */
    #[Test]
    public function successfulResultYieldsASuccessfulTurnCompletedCarryingTheSessionId(): void
    {
        self::assertSame(
            ['turn ok:4c880ba7-f788-4501-9ef5-54f486e1a165'],
            self::parse(self::observedLine('"type":"result"')),
        );
    }

    /** A failed turn still ends the turn: the caller is waiting for exactly one completion. */
    #[Test]
    public function erroredResultYieldsAFailedTurnCompleted(): void
    {
        self::assertSame(
            ['turn failed:4c880ba7-f788-4501-9ef5-54f486e1a165'],
            self::parse(self::fixtureLine('synthetic-result-error.jsonl')),
        );
    }

    /** A result that cannot be read in full still ends the turn, and is never upgraded to a success. */
    #[DataProvider('resultLinesThatCannotBeReadInFull')]
    #[Test]
    public function resultLinesThatCannotBeReadInFullStillCompleteTheTurn(string $line, string $expected): void
    {
        self::assertSame([$expected], self::parse($line));
    }

    /** @return iterable<string, array{string, string}> */
    public static function resultLinesThatCannotBeReadInFull(): iterable
    {
        // An outcome that cannot be read is reported as a failure, never as a success.
        yield 'no is_error' => ['{"type":"result","session_id":"s-1"}', 'turn failed:s-1'];
        yield 'is_error is the string false' => [
            '{"type":"result","is_error":"false","session_id":"s-1"}',
            'turn failed:s-1',
        ];
        // An unreadable session id does not by itself make the turn a failure.
        yield 'no session_id' => ['{"type":"result","is_error":false}', 'turn ok:'];
        yield 'session_id is numeric' => ['{"type":"result","is_error":false,"session_id":7}', 'turn ok:'];
    }

    /** Line types the parser does not translate, each taken from the captured turn. */
    #[DataProvider('observedLinesThatCarryNoEvent')]
    #[Test]
    public function lineTypesOutsideTheThreeHandledOnesYieldNothing(string $marker): void
    {
        self::assertSame([], self::parse(self::observedLine($marker)));
    }

    /** @return iterable<string, array{string}> */
    public static function observedLinesThatCarryNoEvent(): iterable
    {
        yield 'system/init' => ['"subtype":"init"'];
        yield 'system/post_turn_summary' => ['"subtype":"post_turn_summary"'];
        yield 'system/status' => ['"subtype":"status"'];
        yield 'system/thinking_tokens' => ['"subtype":"thinking_tokens"'];
        yield 'system/hook_started' => ['"subtype":"hook_started"'];
        yield 'system/hook_response' => ['"subtype":"hook_response"'];
        yield 'rate_limit_event' => ['"type":"rate_limit_event"'];
        // The line that carries a tool result. Issue #5 turns it into a ToolCompleted; until the
        // fake CLI reproduces it, it is one of the ignored types.
        yield 'user with a tool_result' => ['"type":"tool_result"'];
    }

    /** Whatever else lands on the stream, a single line can never take the session down. */
    #[DataProvider('linesThatAreNotEvents')]
    #[Test]
    public function unreadableLinesYieldNothingWithoutThrowing(string $line): void
    {
        self::assertSame([], self::parse($line));
    }

    /** @return iterable<string, array{string}> */
    public static function linesThatAreNotEvents(): iterable
    {
        yield 'unknown type' => ['{"type":"brand_new_thing","payload":{}}'];
        yield 'object without a type' => ['{"session_id":"s-1"}'];
        yield 'empty line' => [''];
        yield 'truncated json' => ['{"type":"result"'];
        yield 'plain CLI warning' => ['Warning: no stdin data received in 3s'];
        yield 'json string' => ['"just a string"'];
        yield 'json number' => ['123'];
        yield 'json null' => ['null'];
        yield 'json boolean' => ['true'];
    }

    /**
     * The wire form allows several content blocks in one assistant line, but no probe produced
     * one: all eight assistant lines captured carried exactly one block. This synthetic line pins
     * down what the loop does should the CLI ever emit one; it is not evidence that it does.
     */
    #[Test]
    public function anAssistantLineWithTwoToolCallsYieldsTwoToolStarted(): void
    {
        self::assertSame(['tool started:Bash:toolu_1', 'tool started:Read:toolu_2'], self::parse(
            '{"type":"assistant","message":{"content":['
            . '{"type":"tool_use","id":"toolu_1","name":"Bash"},'
            . '{"type":"tool_use","id":"toolu_2","name":"Read"}]}}',
        ));
    }

    /** A whole captured turn, which also pins the order the events come out in. */
    #[Test]
    public function parsingTheWholeCapturedTurnYieldsTheExpectedEventSequence(): void
    {
        $parser = new ClaudeCliEventParser();

        $described = [];
        foreach (self::readFixture('observed-turn.jsonl') as $line) {
            foreach ($parser->parse($line) as $event) {
                $described[] = self::describe($event);
            }
        }

        self::assertSame(
            [
                'tool started:Bash:toolu_01VjzN7p8DfsYF7jjfwxSkV4',
                'text:h',
                'text:i',
                'turn ok:4c880ba7-f788-4501-9ef5-54f486e1a165',
            ],
            $described,
        );
    }

    /** All five event types are reachable from a single `match` on the event's class. */
    #[Test]
    public function everyEventTypeCanBeDispatchedByMatchingOnItsClass(): void
    {
        $names = [];
        foreach ([
            new TextDelta('hi'),
            new ToolStarted('Bash', 'toolu_1'),
            new ToolCompleted('toolu_1', true),
            new TurnCompleted(true, 's-1'),
            new AgentError('boom'),
        ] as $event) {
            $names[] = match ($event::class) {
                TextDelta::class => 'text delta',
                ToolStarted::class => 'tool started',
                ToolCompleted::class => 'tool completed',
                TurnCompleted::class => 'turn completed',
                AgentError::class => 'error',
            };
        }

        self::assertSame('text delta, tool started, tool completed, turn completed, error', implode(', ', $names));
    }

    /** @return list<string> one string per event, so that a whole parse is one assertion */
    private static function parse(string $line): array
    {
        $described = [];
        foreach (new ClaudeCliEventParser()->parse($line) as $event) {
            $described[] = self::describe($event);
        }

        return $described;
    }

    /** Renders one event as a string, so that a whole parse is compared in a single assertion. */
    private static function describe(AgentEvent $event): string
    {
        if ($event instanceof TextDelta) {
            return "text:{$event->text}";
        }

        if ($event instanceof ToolStarted) {
            return "tool started:{$event->name}:{$event->id}";
        }

        if ($event instanceof ToolCompleted) {
            return 'tool ' . ($event->success ? 'ok' : 'failed') . ":{$event->id}";
        }

        if ($event instanceof TurnCompleted) {
            return 'turn ' . ($event->success ? 'ok' : 'failed') . ":{$event->sessionId}";
        }

        if ($event instanceof AgentError) {
            return "error:{$event->message}";
        }

        return 'unhandled';
    }

    /** @return string the first captured line containing $marker */
    private static function observedLine(string $marker): string
    {
        foreach (self::readFixture('observed-turn.jsonl') as $line) {
            if (str_contains($line, $marker)) {
                return $line;
            }
        }

        self::fail("No line of observed-turn.jsonl contains {$marker}");
    }

    /** Reads a fixture that is expected to hold exactly one line. */
    private static function fixtureLine(string $name): string
    {
        $lines = self::readFixture($name);
        self::assertCount(1, $lines, "Expected exactly one line in {$name}");

        return implode('', $lines);
    }

    /** @return list<string> */
    private static function readFixture(string $name): array
    {
        $lines = file(__DIR__ . '/fixtures/' . $name, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines, "Missing fixture: {$name}");

        return array_values($lines);
    }
}
