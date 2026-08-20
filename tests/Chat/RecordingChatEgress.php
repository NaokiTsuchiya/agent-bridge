<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Chat;

use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;
use PHPUnit\Framework\Assert;

use function count;

/** A front end that sends nowhere and remembers everything it was asked to send. */
final class RecordingChatEgress implements ChatEgress
{
    /** @var list<RecordingStreamHandle> one per turn, oldest first */
    public array $streams = [];

    /** @var list<array{string, string}> the thread and the text of every status shown */
    public array $statuses = [];

    /** {@inheritDoc} */
    #[Override]
    public function open(ThreadId $thread): StreamHandle
    {
        $handle = new RecordingStreamHandle();
        $this->streams[] = $handle;

        return $handle;
    }

    /** {@inheritDoc} */
    #[Override]
    public function status(ThreadId $thread, string $text): void
    {
        $this->statuses[] = [$thread->value, $text];
    }

    /** @return RecordingStreamHandle the stream of the most recent turn */
    public function last(): RecordingStreamHandle
    {
        $handle = $this->streams[count($this->streams) - 1] ?? null;
        Assert::assertInstanceOf(RecordingStreamHandle::class, $handle, 'No stream was ever opened.');

        return $handle;
    }
}
