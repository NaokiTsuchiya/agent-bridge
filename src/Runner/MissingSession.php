<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use NaokiTsuchiya\AgentBridge\Event\TurnCompleted;

/**
 * Whether what just happened says the session a process was told to resume was not there.
 *
 * The question exists because the session a thread continues is derived rather than stored: the
 * derived id may name a session Claude Code no longer has, `--resume` refuses to invent one, and
 * `--session-id` refuses to reuse one — so which of the two applies cannot be known in advance,
 * only guessed and corrected.
 *
 * @api
 */
final class MissingSession
{
    /**
     * How long a failed first turn is given to be the end of the process.
     *
     * Only ever waited on the ambiguous line: a process started with `--resume` that reports a
     * failed turn having produced nothing else. Claude Code answers a session it cannot find
     * exactly that way and then exits, but a session it *did* find can also fail its first turn
     * and live on — and treating that one as missing would start a second process on an id that
     * is taken, which fails outright.
     *
     * The wait has to outlast the gap between that result line and the exit which explains it:
     * 0.62s measured against Claude Code 2.1.223, so this is a wide margin over what was seen,
     * paid only on this one line.
     */
    private const float EXIT_GRACE = 2.0;

    /**
     * @param TurnCompleted|null $completed the turn boundary that raised the question, or null
     *                                      when the process ended without one
     */
    public static function suspected(AgentProcess $process, ?TurnCompleted $completed): bool
    {
        // Anything already handed to the caller settles it: this process was talking to a real
        // session, so a later death is a death, not a wrong guess, and re-sending the prompt to
        // a fresh process would repeat what the caller has seen.
        if ($process->start !== HistoryStart::Continuing || $process->emitted) {
            return false;
        }

        if ($completed === null) {
            return true;
        }

        return !$completed->success && $process->awaitExit(self::EXIT_GRACE);
    }
}
