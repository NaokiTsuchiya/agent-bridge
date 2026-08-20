<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Cli;

use NaokiTsuchiya\AgentBridge\Chat\ChatIngress;
use NaokiTsuchiya\AgentBridge\Pipeline\IncomingMessage;
use Override;

/** A front end that has exactly the messages it was built with, and then no more. */
final class FixedIngress implements ChatIngress
{
    /** @param list<IncomingMessage> $messages what this front end has to say, in order */
    public function __construct(
        private array $messages,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @return iterable<IncomingMessage>
     */
    #[Override]
    public function listen(): iterable
    {
        return $this->messages;
    }
}
