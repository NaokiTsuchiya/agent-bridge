<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Pipeline;

/**
 * Why a turn is a {@see CompletedTurn}: the agent reached the end of it and said it went well.
 *
 * @api
 */
final readonly class Completed
{
    /** @param string $reply everything the agent said, without the tool announcements */
    public function __construct(
        public string $reply,
    ) {}
}
