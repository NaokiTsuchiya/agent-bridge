<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Pipeline;

use Be\Framework\Attribute\Be;
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

use function sprintf;

/**
 * The turn being answered: the agent has been asked, its events have gone out, and what the turn
 * is has been decided but not yet taken.
 *
 * Nothing here ends a turn by throwing. How a turn went is carried as {@see $being}, and the rule
 * in the attribute above hands it to the final object that reason belongs to — so every way a
 * turn can end is a type a caller can be given, rather than something it has to catch.
 *
 * @api
 */
#[Be([CompletedTurn::class, FailedTurn::class])]
final readonly class AnsweringTurn
{
    /** Shown before there is any reply text to show. */
    private const string ACCEPTED = 'Working on it.';

    /**
     * How a tool call is announced.
     *
     * A blank line on either side of a quoted line, so that a front end which renders Markdown
     * shows it as a quote rather than folding it into the reply around it.
     */
    private const string TOOL_NOTICE = "\n> %s\n";

    /**
     * What a finished tool call is announced as, wrapped in the same quoting as its start.
     *
     * It names the call rather than the tool because {@see ToolCompleted} carries the id and not
     * the name.
     */
    private const string TOOL_DONE = '%s done';

    /** The same, for a call that did not go well. */
    private const string TOOL_FAILED = '%s failed';

    /** What a turn stopped by an event no arm handles fails with. */
    private const string NO_ARM = 'No arm for %s.';

    /** Which of the two a turn turned out to be, and everything that final object is built from. */
    public Completed|Failed $being;

    /** Answers the turn, sending it out as it comes. */
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
        $noArm = '';

        try {
            foreach ($runner->send($thread, $text) as $event) {
                match ($event::class) {
                    TextDelta::class => $reply .= self::say($stream, $event->text),
                    ToolStarted::class => $stream->append(self::announce($event->name)),
                    ToolCompleted::class => $stream->append(self::announce(sprintf(
                        $event->success ? self::TOOL_DONE : self::TOOL_FAILED,
                        $event->id,
                    ))),
                    TurnCompleted::class => $success = $event->success,
                    AgentError::class => $error = self::say($stream, $event->message),
                    // Not a fallback: the five arms above are every implementation there is, and a
                    // sixth one has to stop a turn here rather than go out unnoticed.
                    default => $noArm = self::say($stream, sprintf(self::NO_ARM, $event::class)),
                };

                if ($noArm !== '') {
                    break;
                }
            }
        } finally {
            // The reader is left with an open reply otherwise, whatever went wrong upstream. The
            // process is deliberately not closed: it is the thread's, and the next turn resumes it.
            $stream->close();
        }

        $failure = $noArm === '' ? $error : $noArm;
        $this->being = $success && $failure === '' ? new Completed($reply) : new Failed($reply, $failure);
    }

    /** @return string what a tool call's announcement looks like on the reply stream */
    private static function announce(string $what): string
    {
        return sprintf(self::TOOL_NOTICE, $what);
    }

    /** @return string the same text, so that a caller can both send and keep it in one expression */
    private static function say(StreamHandle $stream, string $text): string
    {
        $stream->append($text);

        return $text;
    }
}
