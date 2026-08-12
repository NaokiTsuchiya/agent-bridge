<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use NaokiTsuchiya\AgentBridge\Chat\ChatIngress;
use NaokiTsuchiya\AgentBridge\Pipeline\IncomingMessage;
use Override;
use Swoole\Coroutine\Channel;

use function is_array;

/**
 * The workspace's messages, taken off the channel the Socket Mode client fills.
 *
 * The two halves of the front end meet here: {@see FrameRouter} acknowledges an envelope and pushes
 * its payload without looking at it, and this reads that payload and decides whether it is a message
 * at all ({@see SlackMessage}). Splitting it that way is what keeps Slack's three-second deadline
 * away from anything this does.
 *
 * Nothing here builds a {@see \NaokiTsuchiya\AgentBridge\Thread\ThreadId}: the two parts are handed
 * over as strings and the pipeline decides whether they name a thread, so that this front end cannot
 * accept an id the rest of the application would refuse.
 *
 * Only usable from inside a coroutine, since taking the next payload parks until there is one.
 *
 * @api
 */
final class SlackIngress implements ChatIngress
{
    /**
     * @param Channel        $envelopes the payloads {@see FrameRouter} accepted, oldest first
     * @param ThreadChannels $channels  where each thread's channel is written down for the egress
     */
    public function __construct(
        private Channel $envelopes,
        private SlackIdentity $identity,
        private ThreadChannels $channels,
    ) {}

    /**
     * {@inheritDoc}
     *
     * Ends when the channel is closed, which is the only way to ask a listening front end to stop:
     * a closed channel answers every waiting reader at once, so nothing is left parked.
     *
     * @return iterable<IncomingMessage>
     */
    #[Override]
    public function listen(): iterable
    {
        while (true) {
            // `pop()` answers with `false` on a closed channel, which is how a listening front end
            // is asked to stop.
            $payload = self::asPayload($this->envelopes->pop());

            if ($payload === null) {
                return;
            }

            $message = SlackMessage::from($payload, $this->identity->botUserId);

            if ($message === null) {
                continue;
            }

            // Written down before the message goes out, so that the status shown on the way in
            // already has somewhere to go.
            $this->channels->remember($message->nativeId, $message->channel);

            yield new IncomingMessage(SlackMessage::PLATFORM, $message->nativeId, $message->text);
        }
    }

    /**
     * Whatever came off the channel, as a payload, or null when the channel is done.
     *
     * Taking it as an argument rather than a variable is what keeps its type off a `mixed`
     * assignment, which is the whole reason the pop is not written inline.
     *
     * @return array<array-key, mixed>|null
     *
     * @pure
     */
    private static function asPayload(mixed $popped): ?array
    {
        return is_array($popped) ? $popped : null;
    }
}
