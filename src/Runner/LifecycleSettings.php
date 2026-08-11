<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

/**
 * How long a child may live and how many of them there may be.
 *
 * Separate from {@see ClaudeCliSettings} because these are not decisions about the binary: they
 * would read the same for an agent that was not Claude Code at all. Every value is here rather
 * than in the code that acts on it, so that a deployment can move them and a test can shrink them
 * to fractions of a second.
 *
 * @api
 */
final readonly class LifecycleSettings
{
    /**
     * @param float $idleSeconds  how long a process with no turn to run is kept before it is
     *                            reclaimed. Reclaiming costs nothing but a restart: Claude Code
     *                            keeps the transcript, so the next turn resumes what was there
     * @param float $turnSeconds  how long a turn may go without reaching its completion event
     *                            before the child is killed and the turn ends in an error. Long
     *                            on purpose — a turn that runs tools legitimately takes minutes
     * @param int   $maxProcesses how many children may exist at once. Reaching it reclaims the
     *                            one used least recently, skipping any that is running a turn
     */
    public function __construct(
        public float $idleSeconds = 900.0,
        public float $turnSeconds = 1_800.0,
        public int $maxProcesses = 8,
    ) {}
}
