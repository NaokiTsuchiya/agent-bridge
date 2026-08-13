<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use InvalidArgumentException;
use Override;
use Ray\Di\ProviderInterface;

use function filter_var;
use function getenv;
use function is_int;

use const FILTER_VALIDATE_INT;

/**
 * Where the endpoint the Slack clients are aimed at comes from.
 *
 * A provider for the same reason {@see SlackApiClientProvider} is one: `Ray\Compiler` freezes
 * whatever `toInstance()` is given into a script that ships with the image, so a setting of the
 * machine the process runs on cannot be one.
 *
 * A value that is not a port is refused here rather than where a client is opened: it is a setting
 * somebody got wrong, worth saying so about before a connection is attempted, and nowhere further
 * on can tell a typo from a workspace that is down.
 *
 * @implements ProviderInterface<SlackApiEndpoint>
 *
 * @api
 */
final class SlackApiEndpointProvider implements ProviderInterface
{
    /** Where the host is read from; `AGENT_BRIDGE_` because this project chose the name, not Slack. */
    public const string HOST_VARIABLE = 'AGENT_BRIDGE_SLACK_API_HOST';

    /** Where the port is read from. */
    public const string PORT_VARIABLE = 'AGENT_BRIDGE_SLACK_API_PORT';

    /** Slack's own host, which is where everything goes unless a deployment says otherwise. */
    private const string DEFAULT_HOST = 'slack.com';

    /** HTTPS, which every client is opened with, written the way the variable would hold it. */
    private const string DEFAULT_PORT = '443';

    /**
     * {@inheritDoc}
     *
     * @throws SlackException when the port variable does not hold a port number
     */
    #[Override]
    public function get(): SlackApiEndpoint
    {
        $host = getenv(self::HOST_VARIABLE);
        $port = getenv(self::PORT_VARIABLE);

        // `false` is unset and `''` is what an unset-looking `FOO=` in a unit file or compose file
        // produces. A value that merely looks blank (`"   "`) is one somebody wrote, so it is handed
        // on as it stands rather than guessed away: falling back would aim the process somewhere
        // other than where the deployment said, and say nothing about it.
        return self::endpoint(
            $host === false || $host === '' ? self::DEFAULT_HOST : $host,
            $port === false || $port === '' ? self::DEFAULT_PORT : $port,
        );
    }

    /**
     * @param string $host what the API is asked for
     * @param string $port what the port variable holds, or what it would hold if it said nothing
     *
     * @throws SlackException when that is not a port number
     */
    private static function endpoint(string $host, string $port): SlackApiEndpoint
    {
        $number = filter_var($port, FILTER_VALIDATE_INT);

        if (!is_int($number)) {
            throw self::refusal($port);
        }

        try {
            return new SlackApiEndpoint($host, $number);
        } catch (InvalidArgumentException $exception) {
            throw self::refusal($port, $exception);
        }
    }

    /**
     * @param string                    $value what the variable holds
     * @param ?InvalidArgumentException $why   what the endpoint said about it, when it was asked
     */
    private static function refusal(string $value, ?InvalidArgumentException $why = null): SlackException
    {
        // The value is quoted because it is a setting rather than a secret, and because a value
        // that is blank or padded says nothing about itself once it is loose in a sentence.
        return new SlackException(
            self::PORT_VARIABLE
            . ' must be a whole number between '
            . SlackApiEndpoint::LOWEST_PORT
            . ' and '
            . SlackApiEndpoint::HIGHEST_PORT
            . ", got \"{$value}\".",
            previous: $why,
        );
    }
}
