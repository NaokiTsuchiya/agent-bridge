<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use NaokiTsuchiya\AgentBridge\Chat\StreamHandle;
use Override;

/**
 * One answer, written into the thread while the turn is still running.
 *
 * What arrives here is a fragment at a time, and **only the fragment is ever sent** — resending the
 * answer so far would cost the length of the reply on every call and read as an edit war on Slack's
 * side. Fragments are collected for the length of the throttle rather than sent as they come:
 * `chat.appendStream` allows 100+ calls a minute and a turn produces far more fragments than that.
 *
 * The turn's end is the one moment the throttle is ignored, because a fragment held back there is
 * a fragment nobody ever sees — and a turn can easily finish inside a single window.
 *
 * The fallback is why `chat.postMessage` is still in this adapter: a workspace that will not open a
 * stream gets its answer in one message, exactly as it did before this class existed. It is bound
 * to the *start* of the stream and to nothing else — once the message is in the thread, answering
 * again would say everything twice.
 *
 * @api
 */
final class SlackStreamingReply implements StreamHandle
{
    /** Everything appended since the last send. */
    private string $buffer = '';

    /** Whether the stream could not be opened and the answer is going out as one message. */
    private bool $fallenBack = false;

    /** Whether the reply has been ended, so that a second close cannot end it again. */
    private bool $ended = false;

    /** @param SlackReply $fallback the answer as one message, used only if the stream cannot start */
    public function __construct(
        private SlackStream $stream,
        private Throttle $throttle,
        private SlackReply $fallback,
    ) {}

    /** {@inheritDoc} */
    #[Override]
    public function append(string $delta): void
    {
        $this->buffer .= $delta;

        $due = $this->throttle->due();

        if (!$due) {
            return;
        }

        $this->flush();
    }

    /**
     * {@inheritDoc}
     *
     * Whatever is still collected goes out first, throttle or no throttle.
     */
    #[Override]
    public function close(): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;
        $this->flush();

        if ($this->fallenBack) {
            $this->fallback->close();

            return;
        }

        $this->stream->stop();
    }

    /** Sends everything collected so far, by whichever way is still open. */
    private function flush(): void
    {
        $buffer = $this->buffer;
        $this->buffer = '';

        // A turn that said nothing is not a message worth opening, let alone ending.
        if ($buffer === '') {
            return;
        }

        if ($this->fallenBack) {
            $this->fallback->append($buffer);

            return;
        }

        $this->throttle->mark();

        $streaming = $this->stream->send($buffer);

        if ($streaming) {
            return;
        }

        // Everything collected goes to the message instead, including what this send was carrying.
        $this->fallenBack = true;
        $this->fallback->append($buffer);
    }
}
