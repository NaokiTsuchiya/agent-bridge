<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Di;

use NaokiTsuchiya\AgentBridge\Runner\AgentRunner;
use NaokiTsuchiya\AgentBridge\Runner\SpawnCliRunner;
use Override;
use Ray\Di\AbstractModule;
use Ray\Di\Scope;
use ReflectionException;

/**
 * The whole of what it takes to run this application on the other execution layer.
 *
 * One execution layer named, plus the one number it asks for by name because a scalar cannot be
 * asked for by type. Everything else it is built from is bound where every runner's parts are
 * bound ({@see \NaokiTsuchiya\AgentBridge\Di\AppModule}), which is the point: choosing the other
 * implementation costs a declaration, not a wiring.
 *
 * It is written as a module that **wraps** the application's own rather than one that installs it,
 * because installing merges without overwriting (`Ray\Di\Container::merge()` adds with `+=`), while
 * a wrapped module is merged after this one's `configure()` has run and therefore loses to it —
 * which is what makes the binding below a replacement rather than a duplicate.
 */
final class SpawnRunnerModule extends AbstractModule
{
    /** The binding name the turn allowance is published under, since a `float` cannot be one. */
    private const string TURN_ALLOWANCE = 'turn_allowance';

    /**
     * {@inheritDoc}
     *
     * @throws ReflectionException When toConstructor() is given a class it cannot reflect on, which
     *         the class named right there is not.
     */
    #[Override]
    protected function configure(): void
    {
        $this->bind('')->annotatedWith(self::TURN_ALLOWANCE)->toProvider(TurnAllowanceProvider::class);
        // toConstructor rather than an attribute on the parameter: the runner is plain PHP and
        // stays that way, so nothing outside this file knows the binding name above.
        $this->bind(AgentRunner::class)->toConstructor(SpawnCliRunner::class, [
            'turnSeconds' => self::TURN_ALLOWANCE,
        ])->in(Scope::SINGLETON);
    }
}
