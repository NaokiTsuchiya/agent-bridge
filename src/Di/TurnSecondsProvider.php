<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Di;

use NaokiTsuchiya\AgentBridge\Runner\LifecycleSettings;
use Override;
use Ray\Di\ProviderInterface;

/**
 * How long a turn may run before timing out, taken from the lifecycle settings.
 *
 * @implements ProviderInterface<float>
 *
 * @api
 */
final class TurnSecondsProvider implements ProviderInterface
{
    /** @param LifecycleSettings $limits where the deployment's timeouts are written */
    public function __construct(
        private LifecycleSettings $limits,
    ) {}

    /** {@inheritDoc} */
    #[Override]
    public function get(): float
    {
        return $this->limits->turnSeconds;
    }
}
