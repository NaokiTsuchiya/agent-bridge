<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Support;

use PHPUnit\Framework\Assert;

use function fopen;
use function implode;
use function in_array;
use function microtime;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function strlen;
use function substr;

/**
 * A stream that remembers every write as its own entry, with the moment it happened.
 *
 * A reply arriving "as it is produced" is a statement about **write boundaries**, and those are
 * exactly what an ordinary stream throws away: bytes written one fragment at a time and bytes
 * written in one go are the same buffer afterwards. A stream wrapper is the only place outside the
 * adapter where the boundaries still exist, which is why the observation is made here rather than
 * by spying on the port — what is under test is that the adapter passes each fragment straight
 * through, not that the pipeline handed it several.
 *
 * @mago-expect lint:method-name
 */
final class RecordingStream
{
    /** The scheme, so that `fopen()` reaches this class. */
    private const string SCHEME = 'agent-bridge-recording';

    /**
     * Every write, by stream name, oldest first.
     *
     * Static because a wrapper is instantiated by the engine, on `fopen()`, out of reach of the
     * test that wants to read what it recorded.
     *
     * @var array<string, list<array{string, float}>>
     */
    private static array $writes = [];

    /**
     * The stream this instance is, which is the part of the path after the scheme.
     *
     * Not readonly and not constructor-promoted: the engine builds the object with no arguments
     * and hands the path to {@see self::stream_open()} afterwards.
     */
    private string $name = '';

    /**
     * The context the engine sets on every wrapper instance.
     *
     * Never read here, and declared only because the engine assigns it whether or not a caller
     * passed a context.
     *
     * @var resource|null
     */
    public mixed $context = null;

    /**
     * @param string $name what to call this stream, unique per case
     *
     * @return resource a stream that records instead of going anywhere
     */
    public static function open(string $name): mixed
    {
        $registered = in_array(self::SCHEME, stream_get_wrappers(), strict: true);
        if (!$registered) {
            Assert::assertTrue(stream_wrapper_register(self::SCHEME, self::class), 'The wrapper was refused.');
        }

        self::$writes[$name] = [];

        $scheme = self::SCHEME;
        $stream = fopen("{$scheme}://{$name}", mode: 'wb');
        Assert::assertIsResource($stream);

        return $stream;
    }

    /**
     * @return list<array{string, float}> what was written and when, one entry per write call
     */
    public static function writes(string $name): array
    {
        return self::$writes[$name] ?? [];
    }

    /** @return list<string> the written fragments alone, in order */
    public static function fragments(string $name): array
    {
        $fragments = [];
        foreach (self::writes($name) as [$text]) {
            $fragments[] = $text;
        }

        return $fragments;
    }

    /** @return string everything a reader would have ended up with */
    public static function text(string $name): string
    {
        return implode('', self::fragments($name));
    }

    /**
     * Called by the engine when the stream is opened; the path is the name after the scheme.
     *
     * The engine passes the mode and its flags as well, and neither is declared: a userland call
     * ignores arguments the method does not take, and a recording stream behaves the same way
     * whichever mode it was opened in.
     */
    public function stream_open(string $path): bool
    {
        $scheme = self::SCHEME;
        $this->name = substr($path, strlen("{$scheme}://"));

        return true;
    }

    /** Called by the engine once per write, which is the boundary being kept. */
    public function stream_write(string $data): int
    {
        self::$writes[$this->name][] = [$data, microtime(true)];

        return strlen($data);
    }

    /** Called by the engine on `fflush()`; nothing is held back, so there is nothing to do. */
    public function stream_flush(): bool
    {
        return true;
    }

    /** Called by the engine on `fclose()`. */
    public function stream_close(): void {}
}
