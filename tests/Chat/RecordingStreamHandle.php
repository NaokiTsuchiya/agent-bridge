<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Chat;

use Override;

use function implode;

/**
 * A reply nobody reads, kept fragment by fragment.
 *
 * The fragments are held apart rather than joined on arrival: whether a tool announcement went out
 * as its own piece, and whether it stayed out of the reply text, can only be told from the pieces.
 */
final class RecordingStreamHandle implements StreamHandle
{
    /** @var list<string> every fragment, in the order it was sent */
    public array $appends = [];

    /** How many times this was closed, which must be exactly once. */
    public int $closes = 0;

    /** {@inheritDoc} */
    #[Override]
    public function append(string $delta): void
    {
        $this->appends[] = $delta;
    }

    /** {@inheritDoc} */
    #[Override]
    public function close(): void
    {
        $this->closes++;
    }

    /** @return string what a reader would have ended up with */
    public function joined(): string
    {
        return implode('', $this->appends);
    }
}
