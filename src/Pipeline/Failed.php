<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Pipeline;

/**
 * Why a turn is a {@see FailedTurn}: it ended without an answer the agent stood behind.
 *
 * @api
 */
final readonly class Failed
{
    /**
     * @param string $reply what the reader was told before the turn stopped
     * @param string $error what went wrong, empty when the turn simply ended without success and
     *                      nothing said why
     */
    public function __construct(
        public string $reply,
        public string $error,
    ) {}
}
