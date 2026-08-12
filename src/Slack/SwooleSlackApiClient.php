<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use Override;
use SensitiveParameter;
use Swoole\Coroutine\Http\Client;

use function is_array;
use function json_encode;

/**
 * The Web API over a coroutine HTTP client.
 *
 * `Swoole\Coroutine\Http\Client` and no other: this process keeps a WebSocket, several child agents
 * and every thread's turn on one event loop, and a synchronous client would stop all of them for the
 * length of each call — including the Socket Mode acknowledgements Slack gives three seconds for.
 *
 * The class holds no judgement of its own. What a response body means is {@see SlackApiResponse}'s,
 * so what cannot be exercised without a workspace is only the call itself.
 *
 * @api
 */
final class SwooleSlackApiClient implements SlackApiClient
{
    /** Every Web API method hangs off this. */
    private const string PREFIX = '/api/';

    /**
     * @param SlackBotToken $token   the only thing that carries the secret; read once, into a header
     * @param string        $apiHost where the Web API is asked for
     * @param int           $apiPort the port that host is reached on
     */
    public function __construct(
        #[SensitiveParameter]
        private SlackBotToken $token,
        private HttpClientFactoryInterface $clients,
        private string $apiHost = 'slack.com',
        private int $apiPort = 443,
    ) {}

    /** {@inheritDoc} */
    #[Override]
    public function call(string $method, array $arguments): SlackApiResult
    {
        $body = json_encode($arguments);

        if ($body === false) {
            // Strings under string keys cannot fail to encode; the branch is here because the
            // failure is in the signature, and an unsent call must not be reported as a sent one.
            return new SlackApiResult(ok: false, error: "cannot encode the arguments of {$method}");
        }

        try {
            $client = $this->client();
        } catch (SocketModeException $exception) {
            return new SlackApiResult(ok: false, error: $exception->getMessage());
        }

        // The token goes in the Authorization header and nowhere else: a header is the one place it
        // cannot end up in a URL or a log line.
        $client->setHeaders([
            'Authorization' => "Bearer {$this->token->value}",
            'Content-Type' => 'application/json; charset=utf-8',
        ]);

        $posted = $client->post(self::PREFIX . $method, $body);
        $answer = $client->getBody();
        $status = $client->statusCode;
        $headers = $client->getHeaders();
        $failure = $client->errMsg;
        $client->close();

        // Checked before the body is read at all: a call that never left is a different thing from
        // one Slack refused, and saying so is what makes the difference visible in the log.
        if (!$posted || $answer === false) {
            return new SlackApiResult(ok: false, error: "cannot reach {$method}: {$failure}");
        }

        // The status and the headers travel with the body because a rate limited answer says so in
        // those two and nowhere else: a 429 may carry no Slack JSON at all.
        return SlackApiResponse::of($answer, $status, is_array($headers) ? $headers : []);
    }

    /** @throws SocketModeException when a client cannot be created at all */
    private function client(): Client
    {
        return $this->clients->create($this->apiHost, $this->apiPort);
    }
}
