<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Di;

use NaokiTsuchiya\AgentBridge\Slack\ConnectionSettings;
use Override;
use Ray\Di\ProviderInterface;

/**
 * How many envelope ids are remembered at once, taken out of the settings that hold it.
 *
 * @implements ProviderInterface<int>
 *
 * @api
 */
final class EnvelopeCapacityProvider implements ProviderInterface
{
    /** @param ConnectionSettings $settings where the deployment's connection settings are written */
    public function __construct(
        private ConnectionSettings $settings,
    ) {}

    /** {@inheritDoc} */
    #[Override]
    public function get(): int
    {
        return $this->settings->envelopeCapacity;
    }
}
