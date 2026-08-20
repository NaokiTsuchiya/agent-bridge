<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Pipeline;

use Be\Framework\Attribute\Be;
use BEAR\Resource\ResourceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;

use function array_merge;
use function array_values;
use function dirname;
use function file_get_contents;
use function glob;
use function str_contains;

/**
 * How the stages are tied together, read off the classes themselves.
 *
 * What is worth pinning is that nothing ties them: no stage names the next one anywhere but in its
 * attribute, so the order is a property of the declarations rather than of code that could quietly
 * grow a branch.
 */
final class PipelineDeclarationTest extends TestCase
{
    /**
     * A raw message can only become a resolved thread.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function anIncomingMessageBecomesAResolvedThread(): void
    {
        self::assertSame([ResolvedThread::class], self::becomingOf(IncomingMessage::class));
    }

    /**
     * A resolved thread can only become the turn being answered.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function aResolvedThreadBecomesAnAnsweringTurn(): void
    {
        self::assertSame([AnsweringTurn::class], self::becomingOf(ResolvedThread::class));
    }

    /**
     * The turn being answered can become either of the two turns there are, and the rule that
     * picks between them is the declaration rather than code.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function anAnsweringTurnBecomesOneOfTheTwoTurns(): void
    {
        self::assertSame([CompletedTurn::class, FailedTurn::class], self::becomingOf(AnsweringTurn::class));
    }

    /**
     * Both of the last stages say nothing about what comes next, which is what ends the chain.
     *
     * @param class-string $turn one of the two a chain can end at
     *
     * @throws ReflectionException
     */
    #[DataProvider('finalTurns')]
    #[Test]
    public function aTurnBecomesNothing(string $turn): void
    {
        self::assertSame([], self::becomingOf($turn));
    }

    /** @return iterable<string, array{class-string}> */
    public static function finalTurns(): iterable
    {
        yield 'the turn that was answered' => [CompletedTurn::class];
        yield 'the turn that was not' => [FailedTurn::class];
    }

    /**
     * Be drives the pipeline and the resource layer answers questions about state; a stage that
     * reached for the resource client would be building the chain out of the other one.
     *
     * @throws ReflectionException
     */
    #[DataProvider('sourceDirectories')]
    #[Test]
    public function doesNotBuildThePipelineOnTheResourceLayer(string $directory): void
    {
        $files = glob(dirname(__DIR__, levels: 2) . "/{$directory}/*.php");
        self::assertIsArray($files);
        self::assertNotSame([], $files);

        // Read off the interface rather than written out, so that a rename cannot leave this
        // looking for a name nothing uses any more and passing for that reason.
        $client = new ReflectionClass(ResourceInterface::class)->getShortName();

        foreach ($files as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);
            self::assertFalse(str_contains($source, $client), "{$file} reaches for the resource layer.");
        }
    }

    /** @return iterable<string, array{string}> */
    public static function sourceDirectories(): iterable
    {
        yield 'the pipeline' => ['src/Pipeline'];
        yield 'the ports' => ['src/Chat'];
    }

    /**
     * @param class-string $class
     *
     * @return list<class-string> the classes the given one declares it can become
     *
     * @throws ReflectionException
     */
    private static function becomingOf(string $class): array
    {
        $declared = [];
        foreach (new ReflectionClass($class)->getAttributes(Be::class) as $attribute) {
            $declared[] = (array) $attribute->newInstance()->being;
        }

        return array_values(array_merge(...$declared));
    }
}
