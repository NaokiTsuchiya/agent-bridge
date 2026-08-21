<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use InvalidArgumentException;

use function array_key_exists;
use function array_key_first;
use function count;

/**
 * The envelope ids seen recently, so that a redelivery is handed on only once.
 *
 * A plain associative array with a count limit, deliberately not Swoole's shared-memory table: that
 * one exists to share state between processes, and there is only one here (`docs/poc-design.md` 4.2).
 *
 * @api
 */
final class EnvelopeLog
{
    /**
     * Insertion ordered, oldest first; PHP arrays keep that order, which is what the eviction uses.
     *
     * @var array<string, true>
     */
    private array $ids = [];

    /** @throws InvalidArgumentException when the capacity cannot hold anything */
    public function __construct(
        #[EnvelopeCapacity]
        private int $capacity,
    ) {
        if ($capacity < 1) {
            throw new InvalidArgumentException("An envelope log has to remember at least one id, got {$capacity}.");
        }
    }

    /**
     * Records the id and answers whether it had not been seen before.
     *
     * A known id is not re-inserted: moving it to the end would keep a repeatedly redelivered
     * envelope alive forever while newer ones are evicted around it.
     */
    public function remember(string $envelopeId): bool
    {
        if (array_key_exists($envelopeId, $this->ids)) {
            return false;
        }

        $this->ids[$envelopeId] = true;

        if (count($this->ids) > $this->capacity) {
            $oldest = array_key_first($this->ids);

            if ($oldest !== null) {
                unset($this->ids[$oldest]);
            }
        }

        return true;
    }

    /** How many ids are remembered right now; never more than the capacity. */
    public function count(): int
    {
        return count($this->ids);
    }
}
