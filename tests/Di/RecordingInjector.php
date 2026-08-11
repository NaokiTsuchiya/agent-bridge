<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Di;

use Override;
use Ray\Di\InjectorInterface;
use Ray\Di\Name;

use function count;

/** An injector that builds nothing and remembers what it was asked for. */
final class RecordingInjector implements InjectorInterface
{
    /** @var list<string> every interface asked for, in order */
    public array $asked = [];

    /**
     * {@inheritDoc}
     *
     * Returns a fresh object each call rather than a fixed one, so that a caller which mistook two
     * instances for one shows up as a difference rather than passing by accident.
     */
    #[Override]
    public function getInstance($interface, $name = Name::ANY)
    {
        $this->asked[] = $interface;

        return (object) ['nth' => count($this->asked)];
    }
}
