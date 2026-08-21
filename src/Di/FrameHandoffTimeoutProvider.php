<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Di;

use NaokiTsuchiya\AgentBridge\Slack\ConnectionSettings;
use Override;
use Ray\Di\ProviderInterface;

/**
 * How long a full channel may be waited on when a frame is handed on, taken out of the settings that hold it.
 *
 * @implements ProviderInterface<float>
 *
 * @api
 */
final class FrameHandoffTimeoutProvider implements ProviderInterface
{
    /** @param ConnectionSettings $settings where the deployment's connection settings are written */
    public function __construct(
        private ConnectionSettings $settings,
    ) {}

    /** {@inheritDoc} */
    #[Override]
    public function get(): float
    {
        return $this->settings->frameHandoffTimeout;
    }
}
