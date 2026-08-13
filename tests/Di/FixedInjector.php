<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Di;

use Override;
use PHPUnit\Framework\Assert;
use Ray\Di\InjectorInterface;
use Ray\Di\Name;
use Throwable;

/**
 * An injector that hands out the instances a case prepared, or throws what it prepared instead.
 *
 * {@see RecordingInjector} answers everything with a fresh anonymous object, which is enough for a
 * caller that only passes it on but not for one that uses what it asked for. A command resolves a
 * single real collaborator and then drives it, so it needs the real thing; and the failure to build
 * one is a path of its own, which is why a prepared {@see Throwable} is thrown from here rather than
 * arranged around the injector.
 *
 * @internal
 */
final class FixedInjector implements InjectorInterface
{
    /** @param array<class-string, object|Throwable> $prepared what to answer with, by class asked for */
    public function __construct(
        private array $prepared = [],
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws Throwable what the case prepared for that class, in place of an instance.
     */
    #[Override]
    public function getInstance($interface, $name = Name::ANY)
    {
        $answer = $this->prepared[$interface] ?? null;
        Assert::assertNotNull($answer, "The caller asked for {$interface}, which the case did not prepare.");

        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    }
}
