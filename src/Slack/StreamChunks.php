<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use function explode;
use function implode;
use function mb_substr;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * One send's worth of a reply, told apart into what is the answer and what is a tool call.
 *
 * A {@see \NaokiTsuchiya\AgentBridge\Chat\StreamHandle} carries text and nothing else, so a tool
 * call reaches this adapter already wrapped by the pipeline in a quoted line of its own. Slack has
 * somewhere better to put those — a `task_update` chunk, which it shows as a step rather than as
 * part of the answer — and this is where the one is recognised as the other. The recognition is by
 * whole line: a `>` inside a sentence is a sentence, not a step.
 *
 * @api
 */
final readonly class StreamChunks
{
    /** What Slack calls a step of work in a streamed message. */
    public const string TASK_UPDATE = 'task_update';

    /** What the pipeline wraps a tool announcement in, at the start of a line of its own. */
    private const string QUOTED = '> ';

    /**
     * @param string                      $markdown the answer itself, as it should be sent
     * @param list<array<string, string>> $chunks   the tool calls announced in this send, in order
     */
    private function __construct(
        public string $markdown,
        public array $chunks,
    ) {}

    /**
     * @param string $buffer     everything collected since the last send
     * @param int    $chunkLimit how long one announcement may be, in characters
     */
    public static function of(string $buffer, int $chunkLimit): self
    {
        $text = [];
        $chunks = [];

        foreach (explode("\n", $buffer) as $line) {
            $announced = self::announcementIn($line);

            if ($announced === null) {
                $text[] = $line;

                continue;
            }

            $chunks[] = ['type' => self::TASK_UPDATE, 'title' => mb_substr($announced, start: 0, length: $chunkLimit)];
        }

        $joined = implode("\n", $text);

        // The newlines around an announcement were there to put it on a line of its own. With the
        // announcement gone to a chunk, sending them would leave a blank line in the answer.
        return new self($chunks === [] ? $joined : trim($joined, characters: "\n"), $chunks);
    }

    /** @return string|null what the tool call is called, or null when the line is not one */
    private static function announcementIn(string $line): ?string
    {
        if (!str_starts_with($line, self::QUOTED)) {
            return null;
        }

        $announced = substr($line, strlen(self::QUOTED));

        // A quote mark with nothing after it announces nothing; it stays part of the answer.
        return $announced === '' ? null : $announced;
    }
}
