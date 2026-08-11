<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

/**
 * How a child is asked to relate to what the thread said before.
 *
 * The two are not interchangeable and neither can stand in for the other: Claude Code refuses
 * to continue a history it does not have, and refuses to begin one under an id it already has.
 * Which of the two applies is exactly what {@see PersistentCliRunner} cannot know in advance,
 * and the value is the flag itself so that no mapping has to be kept in step with it.
 *
 * @api
 */
enum HistoryStart: string
{
    /** Pick up where the thread left off; fails when nothing is there to pick up. */
    case Continuing = '--resume';

    /** Begin the thread's history under the derived id; fails when one is already there. */
    case Beginning = '--session-id';
}
