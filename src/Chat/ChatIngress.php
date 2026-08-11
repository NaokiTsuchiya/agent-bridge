<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Chat;

use NaokiTsuchiya\AgentBridge\Pipeline\IncomingMessage;

/**
 * Where the messages to answer come from.
 *
 * @api
 */
interface ChatIngress
{
    /**
     * Every message this front end has for the application, as it arrives.
     *
     * The messages are raw: nothing about them has been checked yet, which is what
     * {@see IncomingMessage} exists to express.
     *
     * @return iterable<IncomingMessage>
     */
    public function listen(): iterable;
}
