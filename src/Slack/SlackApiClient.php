<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

/**
 * The one way this application talks to the Slack Web API.
 *
 * Everything the front end sends goes through here, so a test can drive the whole egress — status,
 * buffering, thread replies — without a token and without a socket. The method name is a parameter
 * rather than a method of its own because this is a transport, and deciding which call to make is
 * the caller's business.
 *
 * @api
 */
interface SlackApiClient
{
    /**
     * @param string                $method    the Web API method, e.g. `chat.postMessage`
     * @param array<string, string> $arguments its arguments, as Slack names them
     *
     * @return SlackApiResult how it went; a call that could not be made at all is a result too
     */
    public function call(string $method, array $arguments): SlackApiResult;
}
