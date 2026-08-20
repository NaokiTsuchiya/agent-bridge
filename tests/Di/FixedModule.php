<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Di;

use Override;
use Ray\Di\AbstractModule;
use Throwable;

/**
 * A module that binds prepared instances or throwing providers for test cases.
 *
 * @internal
 */
final class FixedModule extends AbstractModule
{
    /** @param array<class-string, object|Throwable> $prepared */
    public function __construct(
        private readonly array $prepared = [],
        ?AbstractModule $module = null,
    ) {
        parent::__construct($module);
    }

    /** {@inheritDoc} */
    #[Override]
    protected function configure(): void
    {
        foreach ($this->prepared as $interface => $entry) {
            if ($entry instanceof Throwable) {
                ThrowingProvider::$throwables[$interface] = $entry;
                $this->bind($interface)->toProvider(ThrowingProvider::class, $interface);
                continue;
            }

            $this->bind($interface)->toInstance($entry);
        }
    }
}
