<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Di;

use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliSettings;
use Override;
use Ray\Di\ProviderInterface;

/**
 * How long a process is given to end before being killed, taken from CLI settings.
 *
 * @implements ProviderInterface<float>
 *
 * @api
 */
final class CloseGraceSecondsProvider implements ProviderInterface
{
    /** @param ClaudeCliSettings $settings where the CLI settings are written */
    public function __construct(
        private ClaudeCliSettings $settings,
    ) {}

    /** {@inheritDoc} */
    #[Override]
    public function get(): float
    {
        return $this->settings->closeGraceSeconds;
    }
}
