<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use NaokiTsuchiya\AgentBridge\Chat\StreamHandle;
use Override;

/**
 * One answer, collected while the turn runs and posted once at the end.
 *
 * `chat.postMessage` cannot be appended to, so the fragments are held until the turn is over and go
 * out as one message. That is the whole reason this stage exists as its own issue: the execution
 * layer streams either way, and swapping this for Slack's streaming API later must not require a
 * single change below the port.
 *
 * **The reply is always a thread reply.** A `thread_ts` is on every post, so an answer can never
 * land in the channel next to the question it belongs to.
 *
 * @api
 */
final class SlackReply implements StreamHandle
{
    /** The Web API method a reply is posted with. */
    public const string POST_MESSAGE = 'chat.postMessage';

    /** Everything appended so far, joined. */
    private string $reply = '';

    /**
     * @param string|null $channel  where to post, or null when this thread was never heard from in
     *                              this process and there is nowhere to answer
     * @param string      $nativeId what Slack calls the thread, which is also its `thread_ts`
     */
    public function __construct(
        private SlackApiClient $api,
        private ?string $channel,
        private string $nativeId,
        private SlackLoggerInterface $logger,
    ) {}

    /** {@inheritDoc} */
    #[Override]
    public function append(string $delta): void
    {
        $this->reply .= $delta;
    }

    /**
     * {@inheritDoc}
     *
     * Emptied as it is sent, so that a second close cannot post the same answer twice.
     */
    #[Override]
    public function close(): void
    {
        $reply = $this->reply;
        $this->reply = '';

        // A turn that produced no text is not an empty message worth posting; Slack refuses one
        // anyway, and a reader would only see the noise.
        if ($reply === '') {
            return;
        }

        $channel = $this->channel;

        if ($channel === null) {
            $this->logger->log("no channel is known for {$this->nativeId}; the reply was not posted");

            return;
        }

        $sent = $this->api->call(self::POST_MESSAGE, [
            'channel' => $channel,
            'thread_ts' => $this->nativeId,
            'text' => $reply,
        ]);

        if (!$sent->ok) {
            $this->logger->log("could not post the reply to {$this->nativeId}: {$sent->error}");
        }
    }
}
