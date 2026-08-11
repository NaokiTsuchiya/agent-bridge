<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Override;
use SensitiveParameter;

/**
 * Opens a Socket Mode connection the way Slack documents it: `apps.connections.open`, then upgrade.
 *
 * The class holds no judgement of its own — the body of the response is read by
 * {@see ConnectionOpenResponse}, split by {@see WebsocketEndpoint}, and the clients come from
 * {@see HttpClientFactoryInterface} — so that what cannot be tested without a workspace is only the
 * two calls themselves.
 *
 * @api
 */
final class SwooleSocketModeConnector implements SocketModeConnectorInterface
{
    /** The API method this connector exists to call; not a setting, but the thing it does. */
    private const string OPEN_PATH = '/api/apps.connections.open';

    /**
     * @param SlackAppToken $token   the only thing that carries the secret; read once, into a header
     * @param string        $apiHost where `apps.connections.open` is asked for
     * @param int           $apiPort the port that host is reached on
     */
    public function __construct(
        #[SensitiveParameter]
        private SlackAppToken $token,
        private HttpClientFactoryInterface $clients,
        private string $apiHost = 'slack.com',
        private int $apiPort = 443,
    ) {}

    /** @throws SocketModeException */
    #[Override]
    public function connect(): SocketModeConnectionInterface
    {
        $endpoint = WebsocketEndpoint::fromUrl(ConnectionOpenResponse::websocketUrl($this->open()));
        $socket = $this->clients->create($endpoint->host, $endpoint->port);
        $upgraded = $socket->upgrade($endpoint->path);

        if (!$upgraded) {
            throw new SocketModeException("Cannot upgrade to a WebSocket at {$endpoint->host}: {$socket->errMsg}");
        }

        return new SwooleSocketModeConnection($socket);
    }

    /**
     * The raw body of `apps.connections.open`.
     *
     * The token goes in the Authorization header and nowhere else: Slack rejects it as a POST
     * parameter, and a header is the one place it cannot end up in a URL or a log line. This is the
     * only place the value is read; the token itself has no opinion about HTTP.
     *
     * @throws SocketModeException
     */
    private function open(): string
    {
        $api = $this->clients->create($this->apiHost, $this->apiPort);
        $api->setHeaders([
            'Authorization' => "Bearer {$this->token->value}",
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]);

        $posted = $api->post(self::OPEN_PATH, '');
        $body = $api->getBody();
        $failure = $api->errMsg;
        $api->close();

        if (!$posted || $body === false) {
            throw new SocketModeException("Cannot reach apps.connections.open: {$failure}");
        }

        return $body;
    }
}
