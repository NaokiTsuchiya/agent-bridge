<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Pipeline;

use NaokiTsuchiya\AgentBridge\Thread\ThreadWorkspace;
use Ray\InputQuery\Attribute\Input;

/**
 * A turn that ended without an answer the agent stood behind: it failed, it stopped, or it never
 * said it was done.
 *
 * The reader has still been told whatever the turn got as far as saying, and the reply has been
 * ended — a failed turn is a turn that happened, not a turn that was never taken.
 *
 * @api
 */
final readonly class FailedTurn implements Turn
{
    /** {@inheritDoc} */
    public string $reply;

    /** What went wrong, empty when the turn simply ended without success and nothing said why. */
    public string $error;

    /**
     * @param ThreadWorkspace $workspace the thread this belonged to
     * @param Failed          $being     why this is the turn it is
     */
    public function __construct(
        #[Input]
        public ThreadWorkspace $workspace,
        #[Input]
        Failed $being,
    ) {
        $this->reply = $being->reply;
        $this->error = $being->error;
    }
}
