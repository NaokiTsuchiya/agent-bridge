<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Override;
use Ray\Di\ProviderInterface;
use Swoole\Coroutine\Channel;

/**
 * The queue between the connection and the pipeline.
 *
 * A provider rather than a bound instance because a channel is a runtime resource, and a compiled
 * injector may carry nothing of the sort. It has to be a singleton wherever it is bound: the router
 * fills the one the ingress drains, and a second channel would leave every message unanswered.
 *
 * @implements ProviderInterface<Channel>
 *
 * @api
 */
final class EnvelopeChannelProvider implements ProviderInterface
{
    /**
     * How many payloads may wait.
     *
     * Deep enough that a burst of mentions is taken in while a turn is being answered, shallow
     * enough that a full queue is noticed ({@see FrameRouter} logs and moves on) rather than
     * growing without end. The acknowledgement never waits on this.
     */
    private const int CAPACITY = 64;

    /** {@inheritDoc} */
    #[Override]
    public function get(): Channel
    {
        return new Channel(self::CAPACITY);
    }
}
