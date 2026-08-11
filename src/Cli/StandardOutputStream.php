<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Cli;

use NaokiTsuchiya\AgentBridge\Chat\StreamHandle;
use Override;

use function fwrite;

/**
 * A reply written straight through to a stream, fragment by fragment.
 *
 * No throttling and no buffer: a reader watching a terminal is the one front end that gains
 * nothing from either, and holding fragments back would be the one way this could fail to show
 * that the execution layer streams at all.
 *
 * @api
 */
final class StandardOutputStream implements StreamHandle
{
    /** What ends a reply, so that a shell prompt or the next turn starts on a line of its own. */
    private const string END = "\n";

    /** @param resource $stream where the fragments are written */
    public function __construct(
        private mixed $stream,
    ) {}

    /** {@inheritDoc} */
    #[Override]
    public function append(string $delta): void
    {
        $this->write($delta);
    }

    /**
     * {@inheritDoc}
     *
     * The stream itself is deliberately left open: it belongs to the process, not to this reply,
     * and the next turn on the same thread writes to it again.
     */
    #[Override]
    public function close(): void
    {
        $this->write(self::END);
    }

    /**
     * One fragment, one write.
     *
     * Deliberately not followed by a flush: the command line SAPI writes through on its own
     * (`implicit_flush` is on), while flushing `php://output` by hand would also empty whatever
     * output buffer the process has deliberately put in front of it.
     */
    private function write(string $text): void
    {
        fwrite($this->stream, $text);
    }
}
