<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Chat;

use NaokiTsuchiya\AgentBridge\Chat\ChatEgress;
use NaokiTsuchiya\AgentBridge\Chat\ChatIngress;
use NaokiTsuchiya\AgentBridge\Chat\StreamHandle;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;

use function dirname;
use function file_get_contents;
use function glob;
use function stripos;

/**
 * What the ports promise, and what they must not know.
 *
 * A front end is meant to be swappable, and the way that is lost is one word at a time: a
 * parameter named after one platform's identifier, a method named after one platform's API. So the
 * declarations are read back here, both for their shape and for their vocabulary.
 */
final class PortDeclarationTest extends TestCase
{
    /**
     * Words that belong to one particular front end and to no other.
     *
     * @var list<string>
     */
    private const array FOREIGN_WORDS = ['thread_ts', 'channel', 'slack'];

    /**
     * Messages arrive as raw input, which is what the pipeline's first stage is.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function ingressEnumeratesRawMessages(): void
    {
        $listen = new ReflectionClass(ChatIngress::class)->getMethod('listen');

        self::assertSame([], $listen->getParameters());
        self::assertSame('iterable', self::returnTypeOf(ChatIngress::class, 'listen'));
    }

    /**
     * The way out is a stream per thread, plus a place for what is not the answer itself.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function egressOpensAStreamPerThread(): void
    {
        $open = new ReflectionClass(ChatEgress::class)->getMethod('open');

        self::assertSame(StreamHandle::class, self::returnTypeOf(ChatEgress::class, 'open'));
        self::assertSame(ThreadId::class, self::parameterTypeOf($open->getParameters()[0] ?? null));
        self::assertSame('void', self::returnTypeOf(ChatEgress::class, 'status'));
    }

    /**
     * Appending a fragment and ending the reply is all a stream promises: how often, and by which
     * API, is the adapter's business, and a port that decided it would break one front end or
     * another.
     *
     * @throws ReflectionException
     */
    #[Test]
    public function aStreamOnlyAppendsAndCloses(): void
    {
        $methods = [];
        foreach (new ReflectionClass(StreamHandle::class)->getMethods() as $method) {
            $methods[] = $method->getName();
        }

        self::assertSame(['append', 'close'], $methods);
        self::assertSame('void', self::returnTypeOf(StreamHandle::class, 'append'));
        self::assertSame('void', self::returnTypeOf(StreamHandle::class, 'close'));
    }

    /**
     * @param class-string $port
     *
     * @throws ReflectionException
     */
    #[DataProvider('ports')]
    #[Test]
    public function declaresNothingOfOnePlatformsOwn(string $port): void
    {
        $file = new ReflectionClass($port)->getFileName();
        self::assertIsString($file);

        $source = file_get_contents($file);
        self::assertIsString($source);

        foreach (self::FOREIGN_WORDS as $word) {
            self::assertFalse(stripos($source, $word), "{$port} speaks of \"{$word}\".");
        }
    }

    /** @return iterable<string, array{class-string}> */
    public static function ports(): iterable
    {
        yield 'ingress' => [ChatIngress::class];
        yield 'egress' => [ChatEgress::class];
        yield 'stream' => [StreamHandle::class];
    }

    /** The ports are the only thing in their namespace: an implementation of one belongs elsewhere. */
    #[Test]
    public function areTheOnlyThingInTheirNamespace(): void
    {
        $directory = dirname(__DIR__, levels: 2) . '/src/Chat';
        $files = glob("{$directory}/*.php");
        self::assertIsArray($files);

        self::assertCount(3, $files, 'Only the three ports live here.');
    }

    /**
     * @param class-string $interface
     *
     * @throws ReflectionException
     */
    private static function returnTypeOf(string $interface, string $method): string
    {
        $type = new ReflectionClass($interface)
            ->getMethod($method)
            ->getReturnType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);

        return $type->getName();
    }

    /** @return string the type of the parameter, which has to be there and has to be a single one */
    private static function parameterTypeOf(?ReflectionParameter $parameter): string
    {
        self::assertInstanceOf(ReflectionParameter::class, $parameter);
        $type = $parameter->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);

        return $type->getName();
    }
}
