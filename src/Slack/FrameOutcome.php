<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

/**
 * What one `recv()` on a Socket Mode connection turned out to be.
 *
 * @api
 */
enum FrameOutcome
{
    /** A frame the client can parse. */
    case Text;

    /** Nothing arrived within the timeout; the connection is still up but has gone quiet. */
    case Silence;

    /** A keepalive that has to be answered, and that must not be counted as silence. */
    case Ping;

    /** Traffic that carries nothing for the client, but proves the connection is alive. */
    case Ignored;

    /** The peer closed the connection in an orderly way. */
    case Closed;

    /** The connection is gone; nothing more will arrive on it. */
    case Broken;
}
