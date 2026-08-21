<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Swoole\Coroutine\Channel;

/**
 * A connection loop that opens one connection and then asks itself to stop.
 *
 * The loop never ends on its own — reconnecting is its normal path — so a case that runs a server
 * needs it ended from the inside, and the connector's hook is the only place that is: it fires
 * before the attempt is answered, so the loop returns after the first one without waiting for a
 * timer. What it opened and how long it waited stay readable afterwards, which is how a case says
 * that the connection really was opened alongside the messages rather than before them.
 *
 * @internal
 */
final class OneAttemptClient
{
    /** The loop itself, to be run inside a coroutine. */
    public SocketModeClient $client;

    /** What handed out the connection; its `attempts` says the loop actually ran. */
    public FakeSocketModeConnector $connector;

    /** Every wait the loop asked for, none of which is taken. */
    public RecordingSleeper $sleeper;

    /**
     * @param Channel              $envelopes where accepted payloads are put, as in the real front end
     * @param SlackLoggerInterface $logger    where the loop says what it did
     */
    public function __construct(Channel $envelopes, SlackLoggerInterface $logger)
    {
        $this->sleeper = new RecordingSleeper();
        $this->connector = new FakeSocketModeConnector([], function (): void {
            $this->client->stop();
        });

        $this->client = new SocketModeClient(
            $this->connector,
            new FrameRouter($envelopes, self::log(), $logger, handoffTimeout: 0.001),
            new ReconnectDelay(
                new Backoff(new FixedRandomSource(), base: 1.0, max: 30.0, jitterRatio: 0.5),
                $this->sleeper,
            ),
            $logger,
            silenceTimeout: 60.0,
        );
    }

    /** @return EnvelopeLog one of the default capacity, which is a usable one */
    private static function log(): EnvelopeLog
    {
        try {
            return new EnvelopeLog(1000);
        } catch (InvalidArgumentException $impossible) {
            // Only an unusable capacity is refused, and none is named here; caught rather than
            // declared so that no case has to carry the tag.
            Assert::fail($impossible->getMessage());
        }
    }
}
