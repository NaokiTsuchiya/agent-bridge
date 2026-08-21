<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

/**
 * How a Socket Mode connection is kept alive, and how long its HTTP client waits.
 *
 * Every value is here rather than in the code that acts on it, so that a deployment can move them
 * and a test can shrink them to fractions of a second, the same reasoning {@see \NaokiTsuchiya\AgentBridge\Runner\LifecycleSettings}
 * applies to the execution layer's own timeouts. The backoff's own arithmetic is a separate object
 * ({@see BackoffSettings}) so that neither one carries more parameters than this project's style
 * allows.
 *
 * @api
 */
final readonly class ConnectionSettings
{
    /**
     * @param int   $envelopeCapacity     how many envelope ids are remembered at once ({@see EnvelopeLog})
     * @param float $frameHandoffTimeout  how long a full channel may be waited on when a frame is
     *                                    handed on ({@see FrameRouter})
     * @param float $socketSilenceTimeout how long a connection may produce nothing before it is
     *                                    discarded ({@see SocketModeClient})
     * @param float $httpClientTimeout    the ceiling on a single socket operation, in seconds
     *                                    ({@see SwooleHttpClientFactory})
     */
    public function __construct(
        public int $envelopeCapacity = 1000,
        public float $frameHandoffTimeout = 0.001,
        public float $socketSilenceTimeout = 60.0,
        public float $httpClientTimeout = 60.0,
    ) {}
}
