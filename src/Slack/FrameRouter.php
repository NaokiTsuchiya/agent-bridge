<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Swoole\Coroutine\Channel;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

/**
 * What one received frame means, and what is done about it.
 *
 * Split from {@see SocketModeClient} so that the loop is only about connections and this is only
 * about frames. A frame is never trusted: it arrives over a socket, its shape changes with Slack's
 * releases, and no single odd frame may cost a working connection.
 *
 * @api
 */
final class FrameRouter
{
    /**
     * @param Channel $envelopes      where an accepted payload is handed on; this class never reads it back
     * @param float   $handoffTimeout how long a full channel may be waited on, which is as close to
     *                                zero as it can be: the ack owes Slack an answer within three
     *                                seconds and must not wait on whatever consumes the channel
     */
    public function __construct(
        private Channel $envelopes,
        private EnvelopeLog $seen,
        private SocketModeLoggerInterface $logger,
        private float $handoffTimeout = 0.001,
    ) {}

    /**
     * Handles one frame, answering whether the connection it came from is still worth reading.
     *
     * @throws SocketModeException when the acknowledgement cannot be sent
     */
    public function route(string $frame, SocketModeConnectionInterface $connection): bool
    {
        $decoded = self::asObject(json_decode($frame, associative: true));

        if ($decoded === null) {
            $this->logger->log('discarded a frame that is not a JSON object');

            return true;
        }

        return match (self::text($decoded, 'type')) {
            'hello' => $this->hello(),
            'events_api' => $this->event($decoded, $connection),
            'disconnect' => $this->disconnect($decoded),
            default => $this->unknown(),
        };
    }

    /** The only frame Slack sends unprompted; there is nothing to answer and nothing to hand on. */
    private function hello(): bool
    {
        $this->logger->log('connected');

        return true;
    }

    /** @param array<array-key, mixed> $frame */
    private function disconnect(array $frame): bool
    {
        $reason = self::text($frame, 'reason') ?? 'none given';
        $this->logger->log("Slack asked for a reconnect ({$reason})");

        return false;
    }

    /** A frame type this app does not know is not a reason to give up a working connection. */
    private function unknown(): bool
    {
        $this->logger->log('discarded a frame of an unknown type');

        return true;
    }

    /**
     * Acknowledges the envelope before anything else, then hands the payload on if it is new.
     *
     * The order is the point: the ack owes nothing to the deduplication or to the channel, so a
     * repeat delivery and a stalled consumer both still get answered within Slack's three seconds.
     * A repeat is acknowledged too — a redelivery is what Slack does when an ack went missing, and
     * staying silent the second time would have it redelivered forever.
     *
     * @param array<array-key, mixed> $frame
     *
     * @throws SocketModeException
     */
    private function event(array $frame, SocketModeConnectionInterface $connection): bool
    {
        $id = self::text($frame, 'envelope_id');

        if ($id === null || $id === '') {
            $this->logger->log('discarded an events_api frame without an envelope_id');

            return true;
        }

        $ack = json_encode(['envelope_id' => $id]);

        if ($ack === false) {
            // One string under one key cannot fail to encode; the branch is here because the
            // failure is in the signature, and an unsent ack must not be reported as a sent one.
            $this->logger->log("cannot build the ack for {$id}");

            return true;
        }

        $connection->send($ack);

        return $this->handOn($id, $frame);
    }

    /**
     * Puts the payload on the channel, unless it is a repeat, unusable, or the channel is full.
     *
     * @param array<array-key, mixed> $frame
     */
    private function handOn(string $id, array $frame): bool
    {
        $unseen = $this->seen->remember($id);

        if (!$unseen) {
            $this->logger->log("acknowledged {$id} again without handing it on");

            return true;
        }

        $payload = self::object($frame, 'payload');

        if ($payload === null) {
            $this->logger->log("acknowledged {$id}, which carries no payload object");

            return true;
        }

        $handedOn = $this->envelopes->push($payload, $this->handoffTimeout);

        if (!$handedOn) {
            $this->logger->log("acknowledged {$id}, but the downstream channel would not take it");
        }

        return true;
    }

    /**
     * Whatever `json_decode` answered with, as an object, or null when it is anything else.
     *
     * Taking the decoded value as an argument rather than a variable is what keeps its type off a
     * `mixed` assignment, which is the whole reason the decode is not written inline.
     *
     * @return array<array-key, mixed>|null
     *
     * @pure
     */
    private static function asObject(mixed $decoded): ?array
    {
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @pure
     */
    private static function text(array $node, string $key): ?string
    {
        return is_string($node[$key] ?? null) ? $node[$key] : null;
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>|null
     *
     * @pure
     */
    private static function object(array $node, string $key): ?array
    {
        return is_array($node[$key] ?? null) ? $node[$key] : null;
    }
}
