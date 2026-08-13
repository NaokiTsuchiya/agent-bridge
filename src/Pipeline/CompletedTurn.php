<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Pipeline;

use NaokiTsuchiya\AgentBridge\Thread\ThreadWorkspace;
use Ray\InputQuery\Attribute\Input;

/**
 * A turn that was answered: the agent reached the end of it, said it went well, and everything it
 * said has gone out.
 *
 * There is no flag on it for how it went. One of these existing is what "it went well" means, and
 * a turn that went any other way is a {@see FailedTurn} instead.
 *
 * @api
 */
final readonly class CompletedTurn implements Turn
{
    /** {@inheritDoc} */
    public string $reply;

    /**
     * @param ThreadWorkspace $workspace the thread this answered
     * @param Completed       $being     why this is the turn it is
     */
    public function __construct(
        #[Input]
        public ThreadWorkspace $workspace,
        #[Input]
        Completed $being,
    ) {
        $this->reply = $being->reply;
    }
}
