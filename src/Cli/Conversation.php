<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Cli;

use Be\Framework\BecomingInterface;
use NaokiTsuchiya\AgentBridge\Chat\ChatIngress;
use NaokiTsuchiya\AgentBridge\Pipeline\CompletedTurn;
use NaokiTsuchiya\AgentBridge\Pipeline\Turn;
use NaokiTsuchiya\AgentBridge\Runner\AgentRunner;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use Throwable;

/**
 * Everything one front end has to say, answered one message at a time.
 *
 * The whole of it is `($this->becoming)($message)`: a message goes in and an answered turn comes
 * out, with the worktree, the session, the child process and the streaming of the reply all
 * reached through that one call. Nothing here writes a transition.
 *
 * It receives its collaborators rather than asking an injector for them, which is what lets a
 * process resolve one object and be done. **Letting go of the thread's child is part of the job**:
 * the pool watches its processes on a coroutine of its own, so a conversation that ended without
 * releasing them would keep whoever is waiting on those coroutines waiting.
 *
 * @api
 */
final class Conversation
{
    /**
     * @param BecomingInterface $becoming what turns a message into an answered turn
     * @param AgentRunner       $runner   what the turns are answered by, and what is let go of at
     *                                    the end
     */
    public function __construct(
        private BecomingInterface $becoming,
        private AgentRunner $runner,
    ) {}

    /**
     * @param ChatIngress $ingress where the messages come from; the conversation lasts as long as
     *                             it has any
     *
     * @return ConversationResult how it went, for a caller to act on
     */
    public function answer(ChatIngress $ingress): ConversationResult
    {
        $thread = null;
        $answered = true;

        try {
            foreach ($ingress->listen() as $message) {
                $turn = ($this->becoming)($message);
                $thread = $turn instanceof Turn ? $turn->workspace->thread : $thread;
                $answered = $answered && $turn instanceof CompletedTurn;
            }
        } catch (Throwable $failure) {
            // Carried back rather than thrown on: a conversation is driven from inside a coroutine,
            // where nothing thrown ever reaches the caller — it ends the process instead.
            return new ConversationResult(answered: false, failure: $failure);
        } finally {
            $this->release($thread);
        }

        return new ConversationResult($answered, failure: null);
    }

    /** @param ThreadId|null $thread null when no message ever got as far as naming one */
    private function release(?ThreadId $thread): void
    {
        if ($thread === null) {
            return;
        }

        $this->runner->close($thread);
    }
}
