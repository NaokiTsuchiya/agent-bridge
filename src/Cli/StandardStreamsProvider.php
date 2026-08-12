<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Cli;

use Override;
use Ray\Di\ProviderInterface;

use function fopen;

/**
 * The front end wired to the streams of the process it runs in.
 *
 * A provider rather than a bound instance for the same reason the repository path is one: what it
 * hands over is a runtime resource, and a compiled injector may carry nothing of the sort.
 *
 * @implements ProviderInterface<StandardOutputEgress>
 *
 * @api
 */
final class StandardStreamsProvider implements ProviderInterface
{
    /**
     * The answer goes through the SAPI's own output layer.
     *
     * `php://output` rather than `php://stdout`: whatever wraps this process's output sees the
     * answer that way, and in the command line SAPI that layer is standard output.
     */
    private const string REPLY = 'php://output';

    /** What is going on, kept off the answer so that the answer stays pipeable. */
    private const string STATUS = 'php://stderr';

    /**
     * {@inheritDoc}
     *
     * @throws CliException When either stream cannot be opened.
     */
    #[Override]
    public function get(): StandardOutputEgress
    {
        return new StandardOutputEgress(self::open(self::REPLY), self::open(self::STATUS));
    }

    /**
     * @return resource the opened stream
     *
     * @throws CliException
     */
    private static function open(string $name): mixed
    {
        $stream = fopen($name, mode: 'wb');

        if ($stream === false) {
            throw new CliException("Could not open \"{$name}\" to answer on.");
        }

        return $stream;
    }
}
