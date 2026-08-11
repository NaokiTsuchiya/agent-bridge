<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

/**
 * Whether the connection a `recv()` came back from is still up.
 *
 * The same `false` return means "nothing arrived in time" on a live connection and "the socket is
 * gone" on a dead one, so this is the other half of reading that answer.
 *
 * @api
 */
enum ConnectionState
{
    /** The connection is up; whatever `recv()` said is about this moment, not about the socket. */
    case Alive;

    /** The connection is gone; nothing more will arrive on it. */
    case Gone;
}
