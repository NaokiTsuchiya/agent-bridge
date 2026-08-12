<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use NaokiTsuchiya\AgentBridge\Chat\ChatEgress;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;

/**
 * Which Slack channel each thread was spoken to in.
 *
 * A {@see ThreadId} names the thread and nothing else, while every Web API call needs the channel as
 * well — and {@see ChatEgress} deliberately knows nothing about channels, because a port that did
 * would be a port only Slack could implement. So the ingress writes down what it saw and the egress
 * reads it back, and the two stay joined by the thread id alone.
 *
 * Nothing is persisted. A restart empties this, and the next message on a thread fills it in again
 * before anything is sent — while the session and the worktree, being derived from the thread id,
 * are unaffected either way. That is the same reason there is no store anywhere else in this
 * application, and it is why an entry is never evicted: the map holds one short string per thread
 * spoken to since the process started, and dropping one would silently cost that thread its reply.
 *
 * @api
 */
final class ThreadChannels
{
    /** @var array<string, string> the channel of each thread, by the thread's native id */
    private array $channels = [];

    /**
     * @param string $nativeId what Slack calls the thread
     * @param string $channel  where that thread lives
     */
    public function remember(string $nativeId, string $channel): void
    {
        $this->channels[$nativeId] = $channel;
    }

    /** @return string|null null when nothing has been heard from that thread in this process */
    public function channelFor(string $nativeId): ?string
    {
        return $this->channels[$nativeId] ?? null;
    }
}
