<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Support;

use PHPUnit\Framework\Assert;

use function in_array;
use function stream_wrapper_register;
use function stream_wrapper_restore;
use function stream_wrapper_unregister;
use function strlen;

/**
 * Takes the `php://` scheme over so that a named stream of the process cannot be opened.
 *
 * `php://output` and `php://stderr` always open, which leaves the refusal of whoever opens them
 * unreachable from the outside: the only thing deciding whether a `php://` name opens is the wrapper
 * behind the scheme, and a wrapper can be replaced. Registered under `php` rather than a scheme of
 * its own because the names being refused are the SAPI's own and no caller passes them in.
 *
 * @mago-expect lint:method-name
 */
final class RefusingStream
{
    /** The scheme the streams a process answers on are opened through. */
    private const string SCHEME = 'php';

    /**
     * The stream names this wrapper will not open.
     *
     * Static because a wrapper is instantiated by the engine, on `fopen()`, out of reach of the
     * test that decided what to refuse.
     *
     * @var list<string>
     */
    private static array $refused = [];

    /** Whether the scheme is this class' at the moment; restoring an unchanged one is a notice. */
    private static bool $installed = false;

    /**
     * The context the engine sets on every wrapper instance.
     *
     * Never read here, and declared only because the engine assigns it whether or not a caller
     * passed a context; an undeclared one would be a deprecation.
     *
     * @var resource|null
     */
    public mixed $context = null;

    /** @param list<string> $names the streams that must not open; every other name opens and discards */
    public static function install(array $names): void
    {
        self::$refused = $names;
        Assert::assertTrue(stream_wrapper_unregister(self::SCHEME), 'The scheme could not be taken over.');
        // Set before the registration is checked: a failure there still leaves the scheme this
        // class' to hand back, and a run whose php:// stayed unregistered would be unrecoverable.
        self::$installed = true;
        Assert::assertTrue(stream_wrapper_register(self::SCHEME, self::class), 'The wrapper was refused.');
    }

    /** Hands the scheme back to PHP; does nothing when it was never taken over. */
    public static function restore(): void
    {
        if (!self::$installed) {
            return;
        }

        self::$installed = false;
        self::$refused = [];
        Assert::assertTrue(stream_wrapper_restore(self::SCHEME), 'The scheme could not be handed back.');
    }

    /**
     * Called by the engine when the stream is opened, with the whole name including the scheme.
     *
     * The engine passes the mode and its flags as well, and neither is declared: a userland call
     * ignores arguments the method does not take, and what is refused does not depend on them.
     */
    public function stream_open(string $path): bool
    {
        return !in_array($path, self::$refused, strict: true);
    }

    /** Called by the engine once per write; a discarded stream took everything it was given. */
    public function stream_write(string $data): int
    {
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
