<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Di;

use NaokiTsuchiya\AgentBridge\Runner\LifecycleSettings;
use Override;
use Ray\Di\ProviderInterface;

/**
 * How long a turn may take, taken out of the settings that hold it.
 *
 * A runner that keeps no processes has no use for the rest of {@see LifecycleSettings}, so it asks
 * for this one number. Reading it here rather than binding a literal is what keeps the number a
 * deployment decision: whoever rebinds the settings moves this too.
 *
 * @implements ProviderInterface<float>
 */
final class TurnAllowanceProvider implements ProviderInterface
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
