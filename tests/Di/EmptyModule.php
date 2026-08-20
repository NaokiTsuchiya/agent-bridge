<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Di;

use Override;
use Ray\Di\AbstractModule;

/** A module with no bindings, for the context method the boot sequence never calls. */
final class EmptyModule extends AbstractModule
{
    /** {@inheritDoc} */
    #[Override]
    protected function configure(): void {}
}
