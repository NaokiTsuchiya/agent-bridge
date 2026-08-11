<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use Generator;
use NaokiTsuchiya\AgentBridge\Event\AgentError;
use NaokiTsuchiya\AgentBridge\Event\AgentEvent;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;

/**
 * What a caller is told when a turn ends without an answer.
 *
 * The three endings read alike from the outside and must not: a caller that cannot tell a child
 * that died from one that was still thinking when its time ran out has no way to decide whether
 * asking again is worth anything. The wording is therefore fixed here rather than written where
 * each ending is noticed.
 *
 * @api
 */
final class TurnFailure
{
    /**
     * Which failure a read that came back with nothing is, if it is one at all.
     *
     * The two endings that look alike here are told apart by whether the process still has
     * anything to say: one that has written its last line is gone, one that has not is merely
     * silent, and only the second is a deadline.
     *
     * @param bool $restarted whether this is already the turn's second process
     *
     * @return AgentError|null null when this is not a failure at all but the sign of a session
     *                         that was never there, which a second process is about to answer
     */
    public static function of(Turn $turn, AgentProcess $process, bool $restarted): ?AgentError
    {
        $silent = !$process->outputEnded();
        if ($silent) {
            return self::timedOut($turn->thread, $turn->allowance);
        }

        $wrongGuess = !$restarted && MissingSession::suspected($process, null);

        return $wrongGuess ? null : self::died($process);
    }

    /** @return AgentError when no process could be started at all */
    public static function notStarted(ThreadId $thread): AgentError
    {
        return new AgentError("The agent could not be started for \"{$thread->value}\".");
    }

    /**
     * @param float $seconds how long the turn was given
     *
     * @return AgentError when the turn never reached its completion event
     */
    public static function timedOut(ThreadId $thread, float $seconds): AgentError
    {
        return new AgentError("The agent did not finish the turn for \"{$thread->value}\" within {$seconds} seconds.");
    }

    /**
     * @return AgentError when the process ended in the middle of the turn. Ask before letting go
     *                    of it: afterwards its exit code and its diagnostics are gone
     */
    public static function died(AgentProcess $process): AgentError
    {
        return new AgentError($process->failureMessage());
    }

    /** @return Generator<int, AgentEvent> a turn that is nothing but the failure */
    public static function only(AgentError $error): Generator
    {
        yield $error;
    }
}
