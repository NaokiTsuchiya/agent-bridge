<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use function mb_str_split;

/**
 * One streamed message, from the call that opens it to the call that ends it.
 *
 * Slack's three streaming methods are one conversation: `chat.startStream` answers with the `ts`
 * that `chat.appendStream` and `chat.stopStream` are addressed to, so the `ts` — and the fact that
 * there is not one yet — is what this class is for. What to send and when is
 * {@see SlackStreamingReply}'s.
 *
 * @api
 */
final class SlackStream
{
    /** The Web API method that opens a streamed message. */
    public const string START = 'chat.startStream';

    /** The one that adds to it. */
    public const string APPEND = 'chat.appendStream';

    /** The one that ends it, after which Slack shows the message as finished. */
    public const string STOP = 'chat.stopStream';

    /** What Slack calls this message, empty until the stream has been opened. */
    private string $ts = '';

    /**
     * @param string|null $channel  where to stream, or null when this thread was never heard from
     *                              in this process and there is nowhere to answer
     * @param string      $nativeId what Slack calls the thread, which is also its `thread_ts`
     */
    public function __construct(
        private SlackApiClient $api,
        private ?string $channel,
        private string $nativeId,
        private SlackLoggerInterface $logger,
        private StreamingSettings $settings,
    ) {}

    /**
     * Sends what has been collected, opening the stream if it is not open yet.
     *
     * @param string $buffer everything appended since the last send
     *
     * @return bool whether the stream is carrying the answer; false means it could not be opened
     *              and the caller has to answer some other way
     */
    public function send(string $buffer): bool
    {
        $channel = $this->channel;

        if ($channel === null) {
            return false;
        }

        $split = StreamChunks::of($buffer, $this->settings->maxChunkCharacters);
        $chunks = $split->chunks;

        foreach (self::pieces($split->markdown, $this->settings->maxTextCharacters) as $piece) {
            $streaming = $this->one($channel, $piece, $chunks);

            if (!$streaming) {
                return false;
            }

            // The announcements ride with the first piece; repeating them on the rest would show
            // the same tool call once per piece the answer had to be split into.
            $chunks = [];
        }

        return true;
    }

    /** Ends the message, if one was ever opened. */
    public function stop(): void
    {
        $channel = $this->channel;

        if ($this->ts === '' || $channel === null) {
            return;
        }

        $stopped = $this->api->call(self::STOP, ['channel' => $channel, 'ts' => $this->ts]);

        if ($stopped->ok) {
            return;
        }

        $this->logger->log("could not end the reply in {$this->nativeId}: {$stopped->error}");
    }

    /**
     * @param list<array<string, string>> $chunks the tool calls announced in this send
     *
     * @return bool whether the stream is still carrying the answer
     */
    private function one(string $channel, string $markdown, array $chunks): bool
    {
        if ($this->ts === '') {
            return $this->start($channel, $markdown, $chunks);
        }

        $appended = $this->api->call(self::APPEND, self::arguments([
            'channel' => $channel,
            'ts' => $this->ts,
            'markdown_text' => $markdown,
        ], $chunks));

        if (!$appended->ok) {
            // Deliberately not a reason to answer some other way: the message is already in the
            // thread, and posting the answer again would say the same thing twice. This fragment
            // is lost, the stream goes on.
            $this->logger->log("could not add to the reply in {$this->nativeId}: {$appended->error}");
        }

        return true;
    }

    /**
     * @param list<array<string, string>> $chunks the tool calls announced in this send
     *
     * @return bool whether the stream was opened
     */
    private function start(string $channel, string $markdown, array $chunks): bool
    {
        $started = $this->api->call(self::START, self::arguments([
            'channel' => $channel,
            'thread_ts' => $this->nativeId,
            'markdown_text' => $markdown,
        ], $chunks));

        // A stream that came back without a `ts` cannot be added to or ended, which leaves the
        // answer in the same predicament as one that was refused outright.
        if ($started->ok && $started->ts !== '') {
            $this->ts = $started->ts;

            return true;
        }

        $refusal = $started->ok ? 'no ts came back' : $started->error;
        $this->logger->log(self::START . " was refused for {$this->nativeId} ({$refusal})");

        return false;
    }

    /**
     * @param array<string, string>       $named  the arguments Slack always wants
     * @param list<array<string, string>> $chunks the ones it only wants when there are any
     *
     * @return array<string, mixed>
     *
     * @pure
     */
    private static function arguments(array $named, array $chunks): array
    {
        if ($chunks === []) {
            return $named;
        }

        return [...$named, 'chunks' => $chunks];
    }

    /**
     * @return list<string> the text as sends of at most the allowed length, never none of them
     *
     * @pure
     */
    private static function pieces(string $markdown, int $limit): array
    {
        // An empty piece is still a send when there are announcements riding on it.
        return $markdown === '' ? [''] : mb_str_split($markdown, $limit);
    }
}
