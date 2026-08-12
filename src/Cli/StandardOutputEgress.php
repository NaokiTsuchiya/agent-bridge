<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Cli;

use NaokiTsuchiya\AgentBridge\Chat\ChatEgress;
use NaokiTsuchiya\AgentBridge\Chat\StreamHandle;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Override;

use function fwrite;
use function sprintf;

/**
 * The front end of a terminal: the answer on one stream, what is going on on the other.
 *
 * The two are separated because a command whose answer is worth piping must be able to pipe it —
 * a status folded into the reply would end up in the file the reader redirected the answer into.
 * That is exactly the choice {@see ChatEgress::status()} leaves to the adapter.
 *
 * A tool call is not handled here at all: it arrives through the same {@see StreamHandle} the
 * reply does, already wrapped by the pipeline into a quoted line of its own, and is passed on
 * unchanged. A second wrapping here would be a second answer to the same question.
 *
 * @api
 */
final class StandardOutputEgress implements ChatEgress
{
    /** Marks a status even where both streams are read as one, e.g. under `2>&1`. */
    private const string STATUS = "# %s\n";

    /**
     * @param resource $reply  where the answer goes; standard output in a real run
     * @param resource $status where what is going on goes; standard error in a real run
     */
    public function __construct(
        private mixed $reply,
        private mixed $status,
    ) {}

    /** {@inheritDoc} */
    #[Override]
    public function open(ThreadId $thread): StreamHandle
    {
        return new StandardOutputStream($this->reply);
    }

    /** {@inheritDoc} */
    #[Override]
    public function status(ThreadId $thread, string $text): void
    {
        fwrite($this->status, sprintf(self::STATUS, $text));
    }
}
