<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Pipeline;

use NaokiTsuchiya\AgentBridge\Chat\ChatEgress;
use NaokiTsuchiya\AgentBridge\Chat\StreamHandle;
use NaokiTsuchiya\AgentBridge\Event\AgentError;
use NaokiTsuchiya\AgentBridge\Event\TextDelta;
use NaokiTsuchiya\AgentBridge\Event\ToolCompleted;
use NaokiTsuchiya\AgentBridge\Event\ToolStarted;
use NaokiTsuchiya\AgentBridge\Event\TurnCompleted;
use NaokiTsuchiya\AgentBridge\Runner\AgentRunner;
use NaokiTsuchiya\AgentBridge\Thread\ThreadWorkspace;
use Ray\Di\Di\Inject;
use Ray\InputQuery\Attribute\Input;
use UnhandledMatchError;

use function sprintf;

/**
 * A turn that has been answered and whose events have all gone out.
 *
 * @api
 */
final readonly class CompletedTurn
{
    /** Shown before there is any reply text to show. */
    private const string ACCEPTED = 'Working on it.';

    /**
     * How a tool call is announced.
     *
     * It goes to the same stream as the reply because that is all a {@see StreamHandle} offers, so
     * the two are told apart by this wrapping rather than by where they arrive. A reader sees a
     * quoted line; the reply itself never contains it.
     */
    private const string TOOL_NOTICE = "\n> %s\n";

    /** Everything the agent said, without the tool announcements. */
    public string $reply;

    /** Whether the turn reached its completion event and that event said it went well. */
    public bool $success;

    /** What went wrong instead of a reply, empty when nothing did. */
    public string $error;

    /**
     * Answers the turn, sending it out as it comes.
     *
     * @throws UnhandledMatchError When the execution layer emits an event no arm below handles.
     */
    public function __construct(
        #[Input]
        public ThreadWorkspace $workspace,
        #[Input]
        string $text,
        #[Inject]
        AgentRunner $runner,
        #[Inject]
        ChatEgress $egress,
    ) {
        $thread = $workspace->thread;
        $egress->status($thread, self::ACCEPTED);
        $stream = $egress->open($thread);

        $reply = '';
        $success = false;
        $error = '';

        try {
            foreach ($runner->send($thread, $text) as $event) {
                match ($event::class) {
                    TextDelta::class => $reply .= self::say($stream, $event->text),
                    ToolStarted::class => $stream->append(sprintf(self::TOOL_NOTICE, $event->name)),
                    ToolCompleted::class => null,
                    TurnCompleted::class => $success = $event->success,
                    AgentError::class => $error = self::say($stream, $event->message),
                    // Not a fallback: the five arms above are every implementation there is, and a
                    // sixth one has to stop a turn here rather than go out unnoticed.
                    default => throw new UnhandledMatchError('No arm for ' . $event::class . '.'),
                };
            }
        } finally {
            // The reader is left with an open reply otherwise, whatever went wrong upstream. The
            // process is deliberately not closed: it is the thread's, and the next turn resumes it.
            $stream->close();
        }

        $this->reply = $reply;
        $this->success = $success;
        $this->error = $error;
    }

    /** @return string the same text, so that a caller can both send and keep it in one expression */
    private static function say(StreamHandle $stream, string $text): string
    {
        $stream->append($text);

        return $text;
    }
}
