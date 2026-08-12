<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Be\Framework\BecomingInterface;
use NaokiTsuchiya\AgentBridge\Pipeline\IncomingMessage;
use Swoole\Coroutine;
use Throwable;

/**
 * The resident half of the Slack front end: a connection, and a turn per message.
 *
 * The whole of it is `($this->becoming)($message)` per message, exactly as on the command line —
 * the worktree, the session, the child process and the reply are all reached through that one call.
 * What is different here is that the messages never run out and belong to different threads, so each
 * one is answered on a coroutine of its own.
 *
 * **Nothing dispatches by thread.** A thread already answers one turn at a time, in the execution
 * layer ({@see \NaokiTsuchiya\AgentBridge\Runner\TurnLocks}), and that is also what keeps two turns
 * out of one worktree; threads that are not the same run at the same time and are meant to. A
 * dispatcher here would be a second answer to a question already answered below.
 *
 * @api
 */
final class SlackServer
{
    /**
     * @param BecomingInterface $becoming what turns a message into an answered turn
     * @param SlackIngress      $ingress  the workspace's messages, as they arrive
     * @param SocketModeClient  $client   what puts them there; runs alongside, not before
     */
    public function __construct(
        private BecomingInterface $becoming,
        private SlackIngress $ingress,
        private SocketModeClient $client,
        private SlackLoggerInterface $logger,
    ) {}

    /**
     * Runs until the process is stopped.
     *
     * Only usable from inside a coroutine: everything below it waits on channels.
     */
    public function run(): void
    {
        Coroutine::create(function (): void {
            $this->client->run();
        });

        foreach ($this->ingress->listen() as $message) {
            $this->answer($message);
        }
    }

    /**
     * Answers one message, without letting it hold up the next one.
     *
     * Nothing may be thrown out of the coroutine: what is thrown inside one never reaches a caller,
     * it ends the process — so one malformed thread id would take the whole workspace's bot down.
     */
    private function answer(IncomingMessage $message): void
    {
        Coroutine::create(function () use ($message): void {
            try {
                ($this->becoming)($message);
            } catch (Throwable $failure) {
                $this->logger->log("could not answer a message: {$failure->getMessage()}");
            }
        });
    }
}
