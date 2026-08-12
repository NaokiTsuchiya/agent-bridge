<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Cli;

use Throwable;

/**
 * How a conversation went, in the two facts a caller can act on.
 *
 * They are kept apart because they mean different things to whoever asked: a turn that finished
 * badly is an answer the reader has already seen, while a failure is the reason there is nothing
 * more to read.
 *
 * @api
 */
final readonly class ConversationResult
{
    /**
     * @param bool           $answered whether every message got a turn that finished well
     * @param Throwable|null $failure  what ended the conversation early, or null when nothing did
     */
    public function __construct(
        public bool $answered,
        public ?Throwable $failure,
    ) {}
}
