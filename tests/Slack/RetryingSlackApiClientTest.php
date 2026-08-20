<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

/**
 * What happens to a call Slack answered with "not so fast".
 *
 * A rate limit is the one failure that is not a failure: the same call, made again after the time
 * Slack named, goes through. It is answered here rather than in the reply so that every call this
 * adapter makes — a status, a stream, a message — is covered by one piece of code.
 */
final class RetryingSlackApiClientTest extends TestCase
{
    /** The call is made again after exactly the time Slack asked for. */
    #[Test]
    public function waitsAsLongAsSlackAsksAndTriesAgain(): void
    {
        [$client, $api, $sleeper] = self::client();
        $api->script(SlackStream::APPEND, self::limited(2.0));

        $result = $client->call(SlackStream::APPEND, ['channel' => 'C0CHANNEL']);

        self::assertTrue($result->ok);
        self::assertSame([2.0], $sleeper->delays);
        self::assertSame([SlackStream::APPEND, SlackStream::APPEND], $api->methods());
    }

    /** An ordinary refusal is not waited on: it would say the same thing however long we waited. */
    #[Test]
    public function doesNotWaitOnAnOrdinaryRefusal(): void
    {
        [$client, $api, $sleeper] = self::client();
        $api->refuse(SlackStream::APPEND, 'channel_not_found');

        $result = $client->call(SlackStream::APPEND, ['channel' => 'C0CHANNEL']);

        self::assertFalse($result->ok);
        self::assertSame([], $sleeper->delays);
        self::assertSame([SlackStream::APPEND], $api->methods());
    }

    /** A workspace that answers with a limit for ever does not hold the turn open for ever. */
    #[Test]
    public function givesUpAfterSoManyTries(): void
    {
        [$client, $api, $sleeper] = self::client();
        $api->script(
            SlackStream::APPEND,
            self::limited(1.0),
            self::limited(1.0),
            self::limited(1.0),
            self::limited(1.0),
            self::limited(1.0),
        );

        $result = $client->call(SlackStream::APPEND, ['channel' => 'C0CHANNEL']);

        self::assertFalse($result->ok);
        self::assertCount(new StreamingSettings()->maxRateLimitRetries, $sleeper->delays);
        self::assertCount(new StreamingSettings()->maxRateLimitRetries + 1, $api->methods());
    }

    /** However long Slack asks for, the turn is not parked for it. */
    #[Test]
    public function waitsNoLongerThanTheCap(): void
    {
        [$client, $api, $sleeper] = self::client();
        $api->script(SlackStream::APPEND, self::limited(3_600.0));

        $client->call(SlackStream::APPEND, ['channel' => 'C0CHANNEL']);

        self::assertSame([new StreamingSettings()->maxRetryAfterSeconds], $sleeper->delays);
        self::assertSame(1, count($sleeper->delays));
    }

    /** @return SlackApiResult the answer to a call Slack would not carry out just yet */
    private static function limited(float $seconds): SlackApiResult
    {
        return new SlackApiResult(ok: false, error: 'ratelimited', retryAfter: $seconds);
    }

    /** @return array{RetryingSlackApiClient, FakeSlackApiClient, RecordingSleeper} */
    private static function client(): array
    {
        $api = new FakeSlackApiClient();
        $sleeper = new RecordingSleeper();

        return [new RetryingSlackApiClient($api, $sleeper, new StreamingSettings()), $api, $sleeper];
    }
}
