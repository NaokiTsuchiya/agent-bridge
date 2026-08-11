<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Thread;

use InvalidArgumentException;

use function str_contains;

/**
 * Builds a {@see ThreadId} out of the two parts a front end knows: which platform, and what that
 * platform calls the thread.
 *
 * Exists because {@see ThreadId} is a value object with no binding of its own, while the pipeline
 * has to receive its collaborators through the injector. Every rule about what a thread id may look
 * like stays in {@see ThreadId}; this only joins the parts and adds the one check that cannot live
 * there.
 *
 * @api
 */
final class ThreadIdFactory
{
    /** What separates the two parts of a thread id, and the reason for the check below. */
    private const string SEPARATOR = ':';

    /**
     * @param string $platform which chat platform the thread lives on
     * @param string $nativeId what that platform calls the thread, colons and all
     *
     * @throws InvalidArgumentException When the parts do not make up a valid thread id.
     */
    public function fromParts(string $platform, string $nativeId): ThreadId
    {
        // A thread id is split at its first separator, so a platform carrying one would push part
        // of its own name into the native id and land the thread in another platform's namespace —
        // another session and another worktree. The split has already happened by the time
        // ThreadId sees the value, which is why the check is here rather than there.
        $separator = self::SEPARATOR;
        if (str_contains($platform, $separator)) {
            throw new InvalidArgumentException("A platform must not contain \"{$separator}\", got \"{$platform}\".");
        }

        return new ThreadId($platform . self::SEPARATOR . $nativeId);
    }
}
