<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Di;

use NaokiTsuchiya\AgentBridge\Slack\BackoffSettings;
use Override;
use Ray\Di\ProviderInterface;

/**
 * The wait after the first lost connection, taken out of the settings that hold it.
 *
 * @implements ProviderInterface<float>
 *
 * @api
 */
final class BackoffBaseProvider implements ProviderInterface
{
    /** @param BackoffSettings $settings where the deployment's backoff arithmetic is written */
    public function __construct(
        private BackoffSettings $settings,
    ) {}

    /** {@inheritDoc} */
    #[Override]
    public function get(): float
    {
        return $this->settings->base;
    }
}
