<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Pipeline;

use Be\Framework\Attribute\Be;

/**
 * A message as a front end handed it over: nothing about it has been checked.
 *
 * @api
 */
#[Be(ResolvedThread::class)]
final readonly class IncomingMessage
{
    /**
     * @param string $platform which chat platform this came from
     * @param string $nativeId what that platform calls the thread the message belongs to
     * @param string $text     what to answer
     */
    public function __construct(
        public string $platform,
        public string $nativeId,
        public string $text,
    ) {}
}
