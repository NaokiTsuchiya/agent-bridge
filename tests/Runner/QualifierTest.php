<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Runner;

use Attribute;
use NaokiTsuchiya\AgentBridge\Di\CloseGraceSecondsProvider;
use NaokiTsuchiya\AgentBridge\Di\TurnSecondsProvider;
use NaokiTsuchiya\AgentBridge\Runner\ClaudeCliSettings;
use NaokiTsuchiya\AgentBridge\Runner\CloseGraceSeconds;
use NaokiTsuchiya\AgentBridge\Runner\LifecycleSettings;
use NaokiTsuchiya\AgentBridge\Runner\PersistentCliRunner;
use NaokiTsuchiya\AgentBridge\Runner\ProcessPool;
use NaokiTsuchiya\AgentBridge\Runner\TurnSeconds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ray\Di\Di\Qualifier;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;

use function array_slice;
use function file;
use function file_get_contents;
use function implode;
use function str_contains;
use function strpos;
use function substr;

final class QualifierTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    #[Test]
    public function turnSecondsIsAQualifierAttribute(): void
    {
        $ref = new ReflectionClass(TurnSeconds::class);
        $attributes = $ref->getAttributes(Attribute::class);
        self::assertCount(1, $attributes);
        $first = $attributes[0] ?? null;
        self::assertNotNull($first);
        $attribute = $first->newInstance();
        self::assertInstanceOf(Attribute::class, $attribute);
        self::assertSame(Attribute::TARGET_PARAMETER, $attribute->flags);

        $qualifiers = $ref->getAttributes(Qualifier::class);
        self::assertCount(1, $qualifiers);
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function closeGraceSecondsIsAQualifierAttribute(): void
    {
        $ref = new ReflectionClass(CloseGraceSeconds::class);
        $attributes = $ref->getAttributes(Attribute::class);
        self::assertCount(1, $attributes);
        $first = $attributes[0] ?? null;
        self::assertNotNull($first);
        $attribute = $first->newInstance();
        self::assertInstanceOf(Attribute::class, $attribute);
        self::assertSame(Attribute::TARGET_PARAMETER, $attribute->flags);

        $qualifiers = $ref->getAttributes(Qualifier::class);
        self::assertCount(1, $qualifiers);
    }

    /** The provider reads turn seconds from the lifecycle limits. */
    #[Test]
    public function turnSecondsProviderReturnsTurnSeconds(): void
    {
        $limits = new LifecycleSettings(turnSeconds: 42.5);
        $provider = new TurnSecondsProvider($limits);

        self::assertSame(42.5, $provider->get());
    }

    /** The provider reads close grace seconds from the CLI settings. */
    #[Test]
    public function closeGraceSecondsProviderReturnsCloseGraceSeconds(): void
    {
        $settings = new ClaudeCliSettings(closeGraceSeconds: 7.5);
        $provider = new CloseGraceSecondsProvider($settings);

        self::assertSame(7.5, $provider->get());
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function persistentCliRunnerHasNoDefaultParameters(): void
    {
        $constructor = new ReflectionMethod(PersistentCliRunner::class, '__construct');
        foreach ($constructor->getParameters() as $parameter) {
            self::assertFalse(
                $parameter->isDefaultValueAvailable(),
                "Parameter {$parameter->name} has a default value.",
            );
        }
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function persistentCliRunnerHasNoNewExpressionsInConstructorBody(): void
    {
        $file = new ReflectionClass(PersistentCliRunner::class)->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);

        $constructor = new ReflectionMethod(PersistentCliRunner::class, '__construct');
        $startLine = $constructor->getStartLine();
        $endLine = $constructor->getEndLine();
        self::assertIsInt($startLine);
        self::assertIsInt($endLine);

        $lines = file($file);
        self::assertIsArray($lines);
        $body = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        $bodyOnly = substr($body, (int) strpos($body, needle: '{'));
        self::assertFalse(str_contains($bodyOnly, 'new '), 'Constructor body must not contain new expressions.');
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function persistentCliRunnerTakesFloatTurnSecondsWithQualifier(): void
    {
        $constructor = new ReflectionMethod(PersistentCliRunner::class, '__construct');
        $parameters = $constructor->getParameters();

        $turnSecondsParam = null;
        foreach ($parameters as $parameter) {
            if ($parameter->name !== 'turnSeconds') {
                continue;
            }

            $turnSecondsParam = $parameter;
            break;
        }

        self::assertNotNull($turnSecondsParam);
        $type = $turnSecondsParam->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame('float', $type->getName());

        $attributes = $turnSecondsParam->getAttributes(TurnSeconds::class);
        self::assertCount(1, $attributes);
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function processPoolTakesFloatCloseGraceSecondsWithQualifier(): void
    {
        $constructor = new ReflectionMethod(ProcessPool::class, '__construct');
        $parameters = $constructor->getParameters();

        $graceParam = null;
        foreach ($parameters as $parameter) {
            if ($parameter->name !== 'closeGraceSeconds') {
                continue;
            }

            $graceParam = $parameter;
            break;
        }

        self::assertNotNull($graceParam);
        $type = $graceParam->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame('float', $type->getName());

        $attributes = $graceParam->getAttributes(CloseGraceSeconds::class);
        self::assertCount(1, $attributes);
    }
}
