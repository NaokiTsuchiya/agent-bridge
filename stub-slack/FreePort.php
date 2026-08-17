<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\StubSlack;

use function fclose;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;

/**
 * A TCP port that is free right now, on `127.0.0.1`.
 *
 * Plain `stream_socket_server`, not Swoole: the caller has not entered a coroutine yet when it
 * decides which port to hand the stub's child process.
 */
final class FreePort
{
    /** @throws StubSlackException when the OS will not hand out a port to bind */
    public static function acquire(): int
    {
        $errorCode = 0;
        $errorMessage = '';
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if ($socket === false) {
            throw new StubSlackException("Cannot reserve a free port: {$errorMessage}");
        }

        $name = stream_socket_get_name($socket, remote: false);
        fclose($socket);

        if ($name === false) {
            throw new StubSlackException('Cannot read the address a free port was bound to.');
        }

        // "127.0.0.1:52998" — the address is always this fixed IPv4 literal, so the last colon is
        // unambiguously the one before the port.
        $separator = strrpos($name, needle: ':');

        if ($separator === false) {
            throw new StubSlackException("Cannot read a port out of \"{$name}\".");
        }

        return (int) substr($name, $separator + 1);
    }
}
