<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Pipeline;

use Be\Framework\Attribute\Be;
use InvalidArgumentException;
use NaokiTsuchiya\AgentBridge\Thread\ThreadDerivation;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use NaokiTsuchiya\AgentBridge\Thread\ThreadIdFactory;
use NaokiTsuchiya\AgentBridge\Worktree\WorktreeException;
use NaokiTsuchiya\AgentBridge\Worktree\WorktreeManager;
use Ray\Di\Di\Inject;
use Ray\InputQuery\Attribute\Input;

/**
 * A message whose thread is real: the id is valid, the session it continues is derived, and the
 * directory the work happens in exists.
 *
 * This application keeps no store at all, so everything about a thread is derived from its id. An
 * instance of this class is the proof that the derivation has been carried out and that what it
 * named is there — which is why nothing downstream is given the chance to work with a thread whose
 * id was never checked or whose worktree was never created.
 *
 * @api
 */
#[Be(CompletedTurn::class)]
final readonly class ResolvedThread
{
    /** The thread this message belongs to, in the form the rest of the application speaks. */
    public ThreadId $thread;

    /** The Claude Code session the turn continues. */
    public string $sessionId;

    /** The absolute path of the worktree the turn runs in, which exists by the time this returns. */
    public string $worktree;

    /**
     * @throws InvalidArgumentException When the message does not name a valid thread.
     * @throws WorktreeException When the worktree cannot be produced.
     */
    public function __construct(
        #[Input]
        string $platform,
        #[Input]
        string $nativeId,
        #[Input]
        public string $text,
        #[Inject]
        ThreadIdFactory $threads,
        #[Inject]
        WorktreeManager $worktrees,
    ) {
        $this->thread = $threads->fromParts($platform, $nativeId);
        $this->sessionId = ThreadDerivation::sessionId($this->thread);
        $this->worktree = $worktrees->worktreeFor($this->thread);
    }
}
