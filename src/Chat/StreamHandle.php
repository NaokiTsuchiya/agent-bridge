<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Chat;

/**
 * One reply being written, a piece at a time.
 *
 * The only promise is that a fragment can be appended and that the reply can be ended. How the
 * fragments become something a reader sees — one message edited over and over, a native streaming
 * API, a line on standard output — and how often they may be sent belongs to the adapter. Every
 * front end limits that differently, so a rate fixed here would break one of them.
 *
 * @api
 */
interface StreamHandle
{
    /** @param string $delta a fragment to add to what was written before, not the whole reply */
    public function append(string $delta): void;

    /** Ends the reply; appending afterwards is not offered. */
    public function close(): void;
}
