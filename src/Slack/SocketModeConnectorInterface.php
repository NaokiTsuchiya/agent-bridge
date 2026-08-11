<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

/**
 * The seam every connection attempt goes through, so that the client can be driven without Slack.
 *
 * @api
 */
interface SocketModeConnectorInterface
{
    /**
     * Opens a connection, doing whatever handshake the transport needs.
     *
     * @throws SocketModeException when the connection cannot be established
     */
    public function connect(): SocketModeConnectionInterface;
}
