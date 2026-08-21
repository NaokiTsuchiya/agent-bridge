<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Di;

use NaokiTsuchiya\AgentBridge\Slack\ConnectionSettings;
use Override;
use Ray\Di\ProviderInterface;

/**
 * How long a connection may produce nothing before it is discarded, taken out of the settings that hold it.
 *
 * @implements ProviderInterface<float>
 *
 * @api
 */
final class SocketSilenceTimeoutProvider implements ProviderInterface
{
    /** @param ConnectionSettings $settings where the deployment's connection settings are written */
    public function __construct(
        private ConnectionSettings $settings,
    ) {}

    /** {@inheritDoc} */
    #[Override]
    public function get(): float
    {
        return $this->settings->socketSilenceTimeout;
    }
}
