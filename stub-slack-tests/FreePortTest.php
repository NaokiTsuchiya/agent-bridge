<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\StubSlack;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fclose;
use function stream_socket_server;

/**
 * @internal
 */
final class FreePortTest extends TestCase
{
    /**
     * Whatever the OS hands back has to be a real TCP port, not a sentinel value.
     *
     * @throws StubSlackException
     */
    #[Test]
    public function returnsAPortInRange(): void
    {
        $port = FreePort::acquire();

        self::assertGreaterThanOrEqual(1, $port);
        self::assertLessThanOrEqual(65_535, $port);
    }

    /**
     * The port has to still be free once acquire() hands it over — the whole point of asking.
     *
     * @throws StubSlackException
     */
    #[Test]
    public function theReturnedPortCanActuallyBeBoundOnAgain(): void
    {
        $port = FreePort::acquire();

        $socket = stream_socket_server("tcp://127.0.0.1:{$port}");

        self::assertNotFalse($socket, "Port {$port} was not actually free.");
        fclose($socket);
    }

    /**
     * Not a fixed port: two calls in the same run must not collide.
     *
     * @throws StubSlackException
     */
    #[Test]
    public function twoCallsReturnDifferentPorts(): void
    {
        $first = FreePort::acquire();
        $second = FreePort::acquire();

        self::assertNotSame($first, $second);
    }
}
