<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

/**
 * How often something may be sent, as a question that can be asked.
 *
 * The window opens when this is built rather than at the first fragment, so a turn short enough to
 * finish inside it is answered by one call and not by three.
 *
 * @api
 */
final class Throttle
{
    /** How many milliseconds are in a second, the window being given in the former. */
    private const float MILLISECONDS = 1_000.0;

    /** When the last send went out. */
    private float $sentAt;

    /** @param int $milliseconds how long to collect for before sending again */
    public function __construct(
        private ClockInterface $clock,
        private int $milliseconds,
    ) {
        $this->sentAt = $clock->now();
    }

    /** @return bool whether enough time has passed to send again */
    public function due(): bool
    {
        return ($this->clock->now() - $this->sentAt) >= ($this->milliseconds / self::MILLISECONDS);
    }

    /** Starts the window again, from now. */
    public function mark(): void
    {
        $this->sentAt = $this->clock->now();
    }
}
