<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\StubSlack;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fclose;
use function fopen;
use function microtime;
use function rewind;
use function stream_get_contents;

/**
 * Only the reject side of `StubSlackCli::main()` — no port, no server — is reachable here. The
 * accept side enters `Swoole\Coroutine\run()` and blocks until the process is killed, so it can
 * only be exercised as a real child process; {@see \NaokiTsuchiya\AgentBridge\Tests\Integration\SocketModeStubTest}
 * is that other half.
 *
 * @internal
 */
final class StubSlackCliTest extends TestCase
{
    /**
     * No port argument: rejected, not defaulted to something that would silently bind anywhere.
     *
     * @throws StubSlackException
     */
    #[Test]
    public function returnsFailureWhenNoPortIsGiven(): void
    {
        $result = StubSlackCli::main(['stub-slack']);

        self::assertSame(1, $result);
    }

    /**
     * The person invoking this by hand is told why it did nothing.
     *
     * @throws StubSlackException
     */
    #[Test]
    public function writesAUsageMessageToStderr(): void
    {
        $stderr = fopen('php://memory', mode: 'w+');
        self::assertNotFalse($stderr);

        StubSlackCli::main(['stub-slack'], $stderr);

        rewind($stderr);
        $written = stream_get_contents($stderr);
        fclose($stderr);

        self::assertStringContainsString('stub-slack', $written === false ? '' : $written);
    }

    /**
     * Returning without a port means never entering `Coroutine::run()` — the only way to prove
     * that from outside is that the call is synchronous and fast: `Coroutine::run()` would block
     * until this stub was killed, which this test never does.
     *
     * @throws StubSlackException
     */
    #[Test]
    public function returnsWithoutEnteringTheCoroutineRuntime(): void
    {
        $stderr = fopen('php://memory', mode: 'w+');
        self::assertNotFalse($stderr);

        $before = microtime(as_float: true);
        StubSlackCli::main(['stub-slack'], $stderr);
        $after = microtime(as_float: true);

        fclose($stderr);

        self::assertLessThan(0.1, $after - $before);
    }
}
