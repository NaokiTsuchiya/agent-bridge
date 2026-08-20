<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Support;

use Closure;

use function restore_error_handler;
use function set_error_handler;

/**
 * Runs a closure and captures any PHP warnings or notices emitted during its execution.
 *
 * This allows tests that deliberately exercise error paths emitting E_WARNING (such as
 * proc_open failures on unhooked plain execution or stream_select on unselectable streams)
 * to assert the exact warnings without failing PHPUnit's `failOnWarning` configuration.
 */
final class Warnings
{
    /**
     * @param Closure(): void $body the operation to run under warning capture
     *
     * @return list<string> the captured warning/notice messages in order
     */
    public static function captured(Closure $body): array
    {
        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured[] = "{$errno}: {$errstr}";

            return true;
        });

        try {
            $body();
        } finally {
            restore_error_handler();
        }

        return $captured;
    }
}
