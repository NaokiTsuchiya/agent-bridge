<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\StubSlack;

use Closure;
use Swoole\Coroutine\Http\Server;
use Swoole\Exception;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;

use function is_array;
use function json_decode;
use function json_encode;

use const SWOOLE_WEBSOCKET_OPCODE_CLOSE;
use const SWOOLE_WEBSOCKET_OPCODE_PING;
use const SWOOLE_WEBSOCKET_OPCODE_PONG;
use const SWOOLE_WEBSOCKET_OPCODE_TEXT;

/**
 * A TLS `apps.connections.open` + Socket Mode WebSocket, standing in for Slack.
 *
 * Everything Slack-shaped is a constructor argument (the scenario, the ack sink, the observation
 * sink) rather than a decision this class makes, so that its own tests can drive it directly and
 * the CLI entrypoint (`stub-slack/StubSlackCli.php`) can drive the exact same class over a real
 * child process.
 *
 * Must be constructed and run from inside a coroutine (`Swoole\Coroutine\run()`): both binding and
 * the accept loop that `start()` runs need the scheduler.
 *
 * @mago-expect lint:too-many-methods
 */
final class StubSlackServer
{
    /** The one method the production connector ever calls; the path is part of its contract. */
    private const string OPEN_PATH = '/api/apps.connections.open';

    /** Where the URL from `OPEN_PATH` points the client back to upgrade on. */
    private const string SOCKET_MODE_PATH = '/socket-mode';

    /** How long a connection is given to answer an expected inbound frame before this stub gives up. */
    private const float RECEIVE_TIMEOUT = 5.0;

    /** The coroutine HTTP+WebSocket server this class wires the scenario onto. */
    private readonly Server $server;

    /** How many times `apps.connections.open` has been called on this stub. */
    private int $connectionsOpen = 0;

    /** How many upgraded WebSocket connections this stub has accepted. */
    private int $upgrades = 0;

    /**
     * @param Closure(string): void $onAck              the raw ack frame's text, once one arrives
     * @param null|Closure(string): void $onObservation a line-oriented observer for upgrade/open/pong/close events
     *
     * @throws Exception when the TLS listener cannot be bound
     *
     * @mago-expect lint:excessive-parameter-list
     */
    public function __construct(
        string $host,
        int $port,
        SelfSignedCertificate $certificate,
        private readonly StubSlackScenario $scenario,
        private readonly Closure $onAck,
        private readonly StubSlackApi $api,
        private readonly ?Closure $onObservation = null,
    ) {
        $this->server = new Server($host, $port, ssl: true);
        $this->server->set([
            'ssl_cert_file' => $certificate->certFile,
            'ssl_key_file' => $certificate->keyFile,
            'open_websocket_pong_frame' => true,
        ]);
        $this->server->handle(self::OPEN_PATH, $this->connectionsOpen(...));
        $this->server->handle(self::SOCKET_MODE_PATH, $this->socketMode(...));

        foreach (StubSlackApi::METHODS as $method) {
            $this->server->handle("/api/{$method}", function (Request $request, Response $response) use (
                $method,
            ): void {
                $this->webApi($method, $request, $response);
            });
        }
    }

    /** Blocks until {@see shutdown()} is called (or the process is killed) accepting connections. */
    public function start(): void
    {
        $this->server->start();
    }

    /** Lets {@see start()} return; only meaningful for a server run inside its caller's process. */
    public function shutdown(): void
    {
        $this->server->shutdown();
    }

    /**
     * Answers with a `wss://` URL that names this same stub — the one thing
     * {@see \NaokiTsuchiya\AgentBridge\Slack\WebsocketEndpoint} requires of it.
     *
     * The `Authorization` header the connector sends is not inspected: this stub has exactly one
     * caller (a fixed integration scenario), so there is no second input class to answer
     * differently for.
     */
    private function connectionsOpen(Request $_request, Response $response): void
    {
        $this->connectionsOpen++;
        $this->observe("OPEN {$this->connectionsOpen}");
        $body = json_encode([
            'ok' => true,
            'url' => "wss://{$this->server->host}:{$this->server->port}" . self::SOCKET_MODE_PATH,
        ]);

        // One boolean and one string built from known-good scalars cannot fail to encode.
        $response->header('Content-Type', 'application/json');
        $response->end($body === false ? '' : $body);
    }

    /** Runs the configured connection script for this upgraded socket. */
    private function socketMode(Request $_request, Response $response): void
    {
        $upgraded = $response->upgrade();

        if (!$upgraded) {
            return;
        }

        $this->upgrades++;
        $this->observe("UPGRADE {$this->upgrades}");

        foreach ($this->scenario->stepsFor($this->upgrades) as $step) {
            $keepGoing = $this->runStep($response, $step);

            if (!$keepGoing) {
                return;
            }
        }
    }

    /** Emits a line-oriented observation when a caller asked for one. */
    private function observe(string $event): void
    {
        if (!$this->onObservation instanceof Closure) {
            return;
        }

        ($this->onObservation)($event);
    }

    /**
     * Executes one step of the accepted connection script.
     *
     * @return bool false only when the connection script should stop immediately
     */
    private function runStep(Response $response, string $step): bool
    {
        return match ($step) {
            StubSlackScenario::SEND_HELLO => $response->push($this->scenario->helloFrame),
            StubSlackScenario::SEND_EVENT => $response->push($this->scenario->eventsFrame),
            StubSlackScenario::SEND_PING => $response->push('', SWOOLE_WEBSOCKET_OPCODE_PING),
            StubSlackScenario::EXPECT_ACK => $this->captureAck($response),
            StubSlackScenario::EXPECT_PONG => $this->capturePong($response),
            StubSlackScenario::CLOSE => $this->closeConnection($response),
            default => throw new StubSlackException("Unknown connection step: {$step}"),
        };
    }

    /** Waits for the text acknowledgement frame and hands it to the injected sink. */
    private function captureAck(Response $response): bool
    {
        $frame = $response->recv(self::RECEIVE_TIMEOUT);

        if (!$frame instanceof Frame || $frame->opcode !== SWOOLE_WEBSOCKET_OPCODE_TEXT) {
            return false;
        }

        ($this->onAck)($frame->data);

        return true;
    }

    /** Waits for the peer's pong frame and records that it arrived. */
    private function capturePong(Response $response): bool
    {
        $frame = $response->recv(self::RECEIVE_TIMEOUT);

        if (!$frame instanceof Frame || $frame->opcode !== SWOOLE_WEBSOCKET_OPCODE_PONG) {
            return false;
        }

        $this->observe('PONG');

        return true;
    }

    /** Sends a close frame and ends this connection script. */
    private function closeConnection(Response $response): bool
    {
        $this->observe('CLOSE');
        $response->push('', SWOOLE_WEBSOCKET_OPCODE_CLOSE);

        return false;
    }

    /**
     * Answers one Web API call from {@see StubSlackApi}, whatever it decides for this `$method`.
     *
     * The body is decoded and handed over as an array rather than left as raw JSON: every caller
     * that scripts an answer through {@see StubSlackApi} reads what a call carried, and none of
     * them wants to repeat that decoding themselves.
     */
    private function webApi(string $method, Request $request, Response $response): void
    {
        $raw = $request->getContent();
        $answer = $this->api->answer($method, self::arguments($raw === false ? '' : $raw));

        foreach ($answer->headers as $name => $value) {
            $response->header($name, $value);
        }

        $response->header('Content-Type', 'application/json');
        $response->status($answer->status);
        $body = json_encode($answer->body);
        // A canned answer built from known-good scalars and arrays cannot fail to encode.
        $response->end($body === false ? '' : $body);
    }

    /**
     * Whatever `json_decode` answered with, as an object, or empty when it is anything else.
     *
     * Taking the decoded value as a parameter rather than a local variable is what keeps its type
     * off a `mixed` assignment — the same reason {@see \NaokiTsuchiya\AgentBridge\Json::asObject()}
     * is written the way it is; this stub does not depend on that class only because it lives in a
     * separate package (`stub-slack/`) with its own, deliberately small, set of dependencies.
     *
     * @return array<array-key, mixed>
     *
     * @pure
     */
    private static function arguments(string $raw): array
    {
        return self::decoded(json_decode($raw, associative: true));
    }

    /**
     * @return array<array-key, mixed>
     *
     * @pure
     */
    private static function decoded(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
