<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use NaokiTsuchiya\AgentBridge\Chat\ChatEgress;
use NaokiTsuchiya\AgentBridge\Chat\StreamHandle;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;

/**
 * The front end of a Slack workspace: the answer as a thread reply, the status wherever it fits.
 *
 * A tool call is not handled here at all. It arrives through the same {@see StreamHandle} the reply
 * does, already wrapped by the pipeline into a quoted line of its own, and is passed on unchanged —
 * which Slack renders as a block quote, apart from the answer. A second wrapping here would be a
 * second answer to the same question.
 *
 * @api
 */
final class SlackEgress implements ChatEgress
{
    /**
     * The method the status is shown with, and the reason there is a fallback below it.
     *
     * Slack documents it for assistant threads, and **nothing in the documentation says it works in
     * an ordinary channel's thread** (`docs/poc-design.md` 4.5, measured 2026-08). Rather than bet
     * on one reading, the call is made and its answer believed: a workspace where it works gets the
     * status line it is meant to have, and one where it does not gets a message instead.
     */
    private const string SET_STATUS = 'assistant.threads.setStatus';

    /** @param ThreadChannels $channels where the ingress wrote down which channel a thread lives in */
    public function __construct(
        private SlackApiClient $api,
        private ThreadChannels $channels,
        private SlackLoggerInterface $logger,
    ) {}

    /** {@inheritDoc} */
    #[Override]
    public function open(ThreadId $thread): StreamHandle
    {
        return new SlackReply(
            $this->api,
            $this->channels->channelFor($thread->nativeId),
            $thread->nativeId,
            $this->logger,
        );
    }

    /** {@inheritDoc} */
    #[Override]
    public function status(ThreadId $thread, string $text): void
    {
        $channel = $this->channels->channelFor($thread->nativeId);

        if ($channel === null) {
            $this->logger->log("no channel is known for {$thread->nativeId}; the status was not shown");

            return;
        }

        $shown = $this->api->call(self::SET_STATUS, [
            'channel_id' => $channel,
            'thread_ts' => $thread->nativeId,
            'status' => $text,
        ]);

        if ($shown->ok) {
            return;
        }

        $this->logger->log(self::SET_STATUS . " was refused ({$shown->error}); showing the status as a message");

        $this->api->call(SlackReply::POST_MESSAGE, [
            'channel' => $channel,
            'thread_ts' => $thread->nativeId,
            'text' => $text,
        ]);
    }
}
