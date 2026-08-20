<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Di;

use Override;
use Ray\Di\ProviderInterface;
use Ray\Di\SetContextInterface;
use RuntimeException;
use Throwable;

/**
 * A provider that throws what a test case prepared for a specific class or interface.
 *
 * @internal
 *
 * @implements ProviderInterface<never>
 */
final class ThrowingProvider implements ProviderInterface, SetContextInterface
{
    /** @var array<string, Throwable> */
    public static array $throwables = [];

    /** The context string naming the requested interface. */
    private string $context = '';

    /** {@inheritDoc} */
    #[Override]
    public function setContext($context): void
    {
        $this->context = $context;
    }

    /**
     * {@inheritDoc}
     *
     * @throws Throwable what the test case prepared for this context.
     */
    #[Override]
    public function get(): never
    {
        $throwable = self::$throwables[$this->context] ?? null;
        if ($throwable !== null) {
            throw $throwable;
        }

        throw new RuntimeException("No throwable prepared for context: {$this->context}");
    }
}
