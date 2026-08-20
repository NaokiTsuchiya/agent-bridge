<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Cli;

use NaokiTsuchiya\AgentBridge\Chat\ChatIngress;
use NaokiTsuchiya\AgentBridge\Pipeline\IncomingMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fopen;
use function fwrite;
use function rewind;

/**
 * What arrives on standard input, turned into messages nobody has checked yet.
 *
 * The thread id is split here and judged elsewhere, so what is pinned is the split itself: it is
 * the one place a front end could quietly send a conversation to another thread's worktree.
 *
 * @mago-expect lint:too-many-methods
 */
final class StandardInputIngressTest extends TestCase
{
    /** Messages arrive; that is the port. */
    #[Test]
    public function isAChatIngress(): void
    {
        self::assertInstanceOf(ChatIngress::class, $this->ingress('cli:x', ''));
    }

    /** A line is a message. */
    #[Test]
    public function readsOneMessagePerLine(): void
    {
        $messages = $this->messages('cli:x', "first\nsecond\n");

        self::assertCount(2, $messages);
        self::assertSame('first', self::nth($messages, index: 0)->text);
        self::assertSame('second', self::nth($messages, index: 1)->text);
    }

    /** The newline is the separator, not part of what the agent is asked. */
    #[Test]
    public function leavesTheNewlineOutOfTheMessage(): void
    {
        self::assertSame('hello', $this->only('cli:x', "hello\n")->text);
    }

    /** Input that never ends in a newline still ends in a message. */
    #[Test]
    public function readsALastLineWithoutANewline(): void
    {
        self::assertSame('hello', $this->only('cli:x', 'hello')->text);
    }

    /** Pressing return is not a question. */
    #[Test]
    public function skipsBlankLines(): void
    {
        $messages = $this->messages('cli:x', "first\n\n   \nsecond\n");

        self::assertCount(2, $messages);
        self::assertSame('second', self::nth($messages, index: 1)->text);
    }

    /** Nothing to answer is not an error, and not a turn either. */
    #[Test]
    public function readsNothingOutOfNothing(): void
    {
        self::assertSame([], $this->messages('cli:x', ''));
    }

    /**
     * The two parts of a thread id, as the rest of the application expects to receive them.
     *
     * @param string $thread   what was given on the command line
     * @param string $platform which platform the message must be said to come from
     * @param string $nativeId what that platform must be said to call the thread
     */
    #[DataProvider('threadIds')]
    #[Test]
    public function splitsTheThreadIdAtTheFirstSeparator(string $thread, string $platform, string $nativeId): void
    {
        $message = $this->only($thread, "hello\n");

        self::assertSame($platform, $message->platform);
        self::assertSame($nativeId, $message->nativeId);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function threadIds(): iterable
    {
        yield 'the ordinary shape' => ['cli:my-experiment', 'cli', 'my-experiment'];
        // Splitting anywhere but at the first separator would send this to another thread.
        yield 'a native id with separators of its own' => ['slack:C123:456', 'slack', 'C123:456'];
        yield 'a native id that is only separators' => ['cli:::', 'cli', '::'];
        // Handed on as it is: what makes a valid thread id is not this class's judgement.
        yield 'no separator at all' => ['nocolon', 'nocolon', ''];
        yield 'nothing after the separator' => ['cli:', 'cli', ''];
        yield 'nothing before the separator' => [':x', '', 'x'];
    }

    /** @return list<IncomingMessage> what the front end had, in order */
    private function messages(string $thread, string $input): array
    {
        $messages = [];
        foreach ($this->ingress($thread, $input)->listen() as $message) {
            self::assertInstanceOf(IncomingMessage::class, $message);
            $messages[] = $message;
        }

        return $messages;
    }

    /** @return IncomingMessage the single message the input was expected to make */
    private function only(string $thread, string $input): IncomingMessage
    {
        $messages = $this->messages($thread, $input);
        self::assertCount(1, $messages);

        return self::nth($messages, index: 0);
    }

    /**
     * @param list<IncomingMessage> $messages
     *
     * @return IncomingMessage the one at that position, which has to be there
     */
    private static function nth(array $messages, int $index): IncomingMessage
    {
        $message = $messages[$index] ?? null;
        self::assertInstanceOf(IncomingMessage::class, $message, "There is no message {$index}.");

        return $message;
    }

    /** @return StandardInputIngress the front end, reading what the case put on the stream */
    private function ingress(string $thread, string $input): StandardInputIngress
    {
        $stream = fopen('php://memory', mode: 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, $input);
        rewind($stream);

        return new StandardInputIngress($thread, $stream);
    }
}
