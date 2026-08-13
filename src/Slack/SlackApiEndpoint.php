<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use function filter_var;
use function getenv;
use function is_int;

use const FILTER_VALIDATE_INT;

/**
 * Where the Slack API is reached: the host and port both the Web API calls and the Socket Mode
 * handshake are aimed at.
 *
 * Read from the environment rather than bound, for the reason {@see SlackApiClientProvider} is a
 * provider: `Ray\Compiler` freezes whatever `toInstance()` is given into a script that ships with
 * the image, so a setting of the running machine cannot be one.
 *
 * The port is checked here rather than where a client is opened, because a wrong port is a wrong
 * setting: it is worth saying so before a connection is attempted, and nowhere further on can tell
 * a typo from a workspace that is down.
 *
 * @api
 */
final readonly class SlackApiEndpoint
{
    /** Where the host is read from; `AGENT_BRIDGE_` because this project chose the name, not Slack. */
    public const string HOST_VARIABLE = 'AGENT_BRIDGE_SLACK_API_HOST';

    /** Where the port is read from. */
    public const string PORT_VARIABLE = 'AGENT_BRIDGE_SLACK_API_PORT';

    /** Slack's own host, which is where everything goes unless a deployment says otherwise. */
    private const string DEFAULT_HOST = 'slack.com';

    /** HTTPS, which every client is opened with. */
    private const int DEFAULT_PORT = 443;

    /** Port 0 asks the OS to pick one, which is meaningless as somewhere to connect to. */
    private const int LOWEST_PORT = 1;

    /** The widest a TCP port number goes. */
    private const int HIGHEST_PORT = 65_535;

    /**
     * @param string $host where the API is asked for
     * @param int    $port the port that host is reached on
     */
    public function __construct(
        public string $host = self::DEFAULT_HOST,
        public int $port = self::DEFAULT_PORT,
    ) {}

    /**
     * The endpoint this process was started with.
     *
     * @throws SlackException when the port variable does not hold a port number
     */
    public static function fromEnvironment(): self
    {
        return new self(self::host(), self::port());
    }

    /** @return string what the host variable names, or Slack's own host */
    private static function host(): string
    {
        $value = getenv(self::HOST_VARIABLE);

        // `false` is unset and `''` is what an unset-looking `FOO=` in a unit file or compose file
        // produces. A value that merely looks blank (`"   "`) is one somebody wrote, so it is handed
        // on as the host rather than guessed away: falling back would connect somewhere other than
        // where the deployment said, and say nothing about it.
        if ($value === false || $value === '') {
            return self::DEFAULT_HOST;
        }

        return $value;
    }

    /**
     * @return int what the port variable holds, or 443
     *
     * @throws SlackException when it holds something that is not a port number
     */
    private static function port(): int
    {
        $value = getenv(self::PORT_VARIABLE);

        if ($value === false || $value === '') {
            return self::DEFAULT_PORT;
        }

        $port = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => self::LOWEST_PORT, 'max_range' => self::HIGHEST_PORT],
        ]);

        if (!is_int($port)) {
            // The value is quoted because it is a setting rather than a secret, and because a value
            // that is blank or padded says nothing about itself once it is loose in a sentence.
            throw new SlackException(
                self::PORT_VARIABLE
                . ' must be a whole number between '
                . self::LOWEST_PORT
                . ' and '
                . self::HIGHEST_PORT
                . ", got \"{$value}\".",
            );
        }

        return $port;
    }
}
