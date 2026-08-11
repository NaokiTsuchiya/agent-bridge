<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Runner;

use NaokiTsuchiya\AgentBridge\Runner\AgentRunner;
use NaokiTsuchiya\AgentBridge\Runner\PersistentCliRunner;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

use function array_map;
use function array_values;
use function dirname;
use function file_get_contents;
use function str_contains;
use function strtolower;

/**
 * Assertions about the interface declaration itself, not about any implementation of it.
 *
 * What an execution layer has to do to identify a conversation, and where it does the work, are
 * its own business. If either leaks into this declaration, the second implementation inherits a
 * shape built for the first — so the words and the signatures are pinned here.
 */
final class AgentRunnerTest extends TestCase
{
    /** @return list<array{string}> the words an implementation detail would be named by */
    public static function leakedWords(): array
    {
        return [['session'], ['resume'], ['claude'], ['cli']];
    }

    /**
     * The one implementation this project has so far.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function persistentRunnerIsAnAgentRunner(): void
    {
        self::assertTrue(new ReflectionClass(PersistentCliRunner::class)->implementsInterface(AgentRunner::class));
    }

    /** Nothing in the declaration names how a conversation is identified, or by which program. */
    #[Test]
    #[DataProvider('leakedWords')]
    public function theDeclarationNamesNoImplementationDetail(string $word): void
    {
        self::assertStringNotContainsString($word, strtolower(self::declaration()));
    }

    /**
     * `send()` takes a thread and a prompt: no directory, under any name.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function sendTakesOnlyAThreadAndAPrompt(): void
    {
        $parameters = self::parametersOf('send');

        self::assertSame(
            ['thread', 'prompt'],
            array_map(static fn(ReflectionParameter $p): string => $p->getName(), $parameters),
        );
        self::assertSame(ThreadId::class, self::typeOf($parameters, position: 0));
        self::assertSame('string', self::typeOf($parameters, position: 1));
    }

    /**
     * `close()` takes a thread and nothing else.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function closeTakesOnlyAThread(): void
    {
        $parameters = self::parametersOf('close');

        self::assertCount(1, $parameters);
        self::assertSame(ThreadId::class, self::typeOf($parameters, position: 0));
    }

    /**
     * No parameter of either method is a place on disk.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function noParameterNamesADirectory(): void
    {
        foreach (['send', 'close'] as $method) {
            foreach (self::parametersOf($method) as $parameter) {
                $name = strtolower($parameter->getName());
                foreach (['dir', 'path', 'cwd', 'folder'] as $word) {
                    self::assertFalse(str_contains($name, $word), "{$method}() takes a {$word}.");
                }
            }
        }
    }

    /**
     * @return list<ReflectionParameter> the declared parameters of one method
     *
     * @throws ReflectionException
     */
    private static function parametersOf(string $method): array
    {
        return array_values(new ReflectionMethod(AgentRunner::class, $method)->getParameters());
    }

    /**
     * @param list<ReflectionParameter> $parameters
     *
     * @return string the declared type at that position, asserted to be a plain named one
     */
    private static function typeOf(array $parameters, int $position): string
    {
        $parameter = $parameters[$position] ?? null;
        self::assertInstanceOf(ReflectionParameter::class, $parameter);
        $type = $parameter->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);

        return $type->getName();
    }

    /** @return string the source of the interface, which is what the word assertions read */
    private static function declaration(): string
    {
        $source = file_get_contents(dirname(__DIR__, levels: 2) . '/src/Runner/AgentRunner.php');
        self::assertIsString($source);

        return $source;
    }
}
