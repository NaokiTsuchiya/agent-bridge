<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Runner;

use NaokiTsuchiya\AgentBridge\Event\ClaudeCliEventParser;
use NaokiTsuchiya\AgentBridge\Event\TextDelta;
use NaokiTsuchiya\AgentBridge\Runner\LineBuffer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function str_split;

/**
 * Where a read from a pipe ends is up to the kernel, so every split has to survive.
 *
 * This is tested here rather than through a live process because a test cannot say where a real
 * `fread` will cut: driving the real thing would pass whenever the line happened to arrive whole,
 * which is most of the time and none of the risk.
 */
final class LineBufferTest extends TestCase
{
    /** Nothing is a line until its newline has arrived. */
    #[Test]
    public function holdsBackAChunkWithNoNewline(): void
    {
        $buffer = new LineBuffer();

        self::assertSame([], $buffer->append('{"type":"result"'));
        self::assertSame(['{"type":"result"}'], $buffer->append("}\n"));
    }

    /** One chunk can carry several lines and the beginning of one more. */
    #[Test]
    public function splitsAChunkThatCarriesSeveralLines(): void
    {
        $buffer = new LineBuffer();

        self::assertSame(['one', 'two'], $buffer->append("one\ntwo\nthr"));
        self::assertSame(['three'], $buffer->append("ee\n"));
    }

    /** A chunk ending on a newline leaves nothing behind. */
    #[Test]
    public function keepsNothingBackWhenAChunkEndsOnANewline(): void
    {
        $buffer = new LineBuffer();

        self::assertSame(['one'], $buffer->append("one\n"));
        self::assertSame(['two'], $buffer->append("two\n"));
    }

    /** Blank lines and empty reads are not lines. */
    #[Test]
    public function dropsBlankLinesAndEmptyChunks(): void
    {
        $buffer = new LineBuffer();

        self::assertSame([], $buffer->append(''));
        self::assertSame(['one'], $buffer->append("\n\none\n\n"));
    }

    /** A JSON line torn into single characters still parses as the one event it was. */
    #[Test]
    public function reassemblesALineTornIntoManyReads(): void
    {
        $line = json_encode([
            'type' => 'stream_event',
            'event' => ['delta' => ['type' => 'text_delta', 'text' => 'hello']],
        ]);
        self::assertIsString($line);

        $buffer = new LineBuffer();
        $parser = new ClaudeCliEventParser();

        $events = [];
        foreach ([...str_split($line, length: 7), "\n"] as $chunk) {
            foreach ($buffer->append($chunk) as $assembled) {
                foreach ($parser->parse($assembled) as $event) {
                    $events[] = $event;
                }
            }
        }

        self::assertCount(1, $events, 'The torn line must become exactly one event.');
        $delta = $events[0] ?? null;
        self::assertInstanceOf(TextDelta::class, $delta);
        self::assertSame('hello', $delta->text);
    }
}
