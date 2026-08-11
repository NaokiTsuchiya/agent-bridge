<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Override;
use SensitiveParameter;
use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\Http\Client\Exception as ClientException;

/**
 * Opens a Socket Mode connection the way Slack documents it: `apps.connections.open`, then upgrade.
 *
 * The class holds no judgement of its own — the body of the response is read by
 * {@see ConnectionOpenResponse} and split by {@see WebsocketEndpoint} — so that what cannot be
 * tested without a workspace is only the two calls themselves.
 *
 * @api
 */
final class SwooleSocketModeConnector implements SocketModeConnectorInterface
{
    /** Where the WSS URL is asked for. */
    private const string API_HOST = 'slack.com';

    /** The one path this connector posts to. */
    private const string OPEN_PATH = '/api/apps.connections.open';

    /** TLS, for both the API call and the WebSocket. */
    private const int HTTPS_PORT = 443;

    /** The token is kept as the value object, which is the only thing that can render the header. */
    public function __construct(
        #[SensitiveParameter]
        private SlackAppToken $token,
    ) {}

    /** @throws SocketModeException */
    #[Override]
    public function connect(): SocketModeConnectionInterface
    {
        $endpoint = WebsocketEndpoint::fromUrl(ConnectionOpenResponse::websocketUrl($this->open()));
        $socket = self::client($endpoint->host, $endpoint->port);

        // A minute is Slack's own connection refresh cycle; the read deadline per frame is the
        // recv loop's business, and it passes its own timeout to every receive().
        $socket->set(['timeout' => 60.0]);

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
        $api = self::client(self::API_HOST, self::HTTPS_PORT);
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

    /** @throws SocketModeException when the client cannot even be created */
    private static function client(string $host, int $port): Client
    {
        try {
            return new Client($host, $port, ssl: true);
        } catch (ClientException $exception) {
            throw new SocketModeException("Cannot open a client for {$host}: {$exception->getMessage()}");
        }
    }
}
