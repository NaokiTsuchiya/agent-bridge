<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Tests\Support;

use PHPUnit\Framework\Assert;

use function array_filter;
use function array_values;
use function explode;
use function fclose;
use function fread;
use function fwrite;
use function getenv;
use function is_resource;
use function json_encode;
use function microtime;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function str_contains;
use function stream_select;
use function stream_set_blocking;
use function usleep;

/**
 * One `claude`-shaped process under test, driven the way the execution layer will drive it.
 *
 * The contract tests run the same body against the fake and against the real binary, so this
 * class may not assume anything the two do not share: no fixed wait for a reply (the real CLI
 * takes seconds, the fake microseconds), and no blocking read (a turn's output arrives while the
 * process is still alive, and a blocking read of a process that has nothing more to say never
 * returns). Both are why waiting is expressed as "poll until the result lines arrive".
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final class CliProcess
{
    /** Everything read from stdout so far, which may end mid-line. */
    private string $out = '';

    /** Everything read from stderr so far. */
    private string $err = '';

    /** Kept because proc_get_status reports the real code once and -1 from then on. */
    private ?int $exitCode = null;

    /**
     * @param resource $process
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $errors
     */
    private function __construct(
        private readonly mixed $process,
        private readonly mixed $stdin,
        private readonly mixed $stdout,
        private readonly mixed $errors,
    ) {}

    /**
     * @param list<string>          $command the binary and its arguments, run without a shell
     * @param array<string, string> $env     added to the caller's environment, not replacing it
     */
    public static function start(array $command, string $cwd, array $env = []): self
    {
        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, $cwd === '' ? null : $cwd, [...getenv(), ...$env]);
        Assert::assertIsResource($process, 'The command under test could not be started.');
        Assert::assertCount(3, $pipes, 'The command under test was started without its three pipes.');

        /** @var array{0: resource, 1: resource, 2: resource} $pipes */
        stream_set_blocking($pipes[1], enable: false);
        stream_set_blocking($pipes[2], enable: false);

        return new self($process, $pipes[0], $pipes[1], $pipes[2]);
    }

    /** Sends one turn, in the stream-json input shape the real CLI expects on stdin. */
    public function send(string $text): void
    {
        $line = json_encode([
            'type' => 'user',
            'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => $text]]],
        ]);
        $this->sendRaw($line === false ? '' : $line);
    }

    /** @param string $line written verbatim, so that a test can send something unparseable */
    public function sendRaw(string $line): void
    {
        fwrite($this->stdin, "{$line}\n");
    }

    /** End of input, which is how a resident process is asked to finish. */
    public function closeStdin(): void
    {
        fclose($this->stdin);
    }

    /**
     * Polls until the process has emitted `$count` result lines in total.
     *
     * @return bool false when the deadline passed or the process died first
     */
    public function waitForResults(int $count, float $timeout = 120.0): bool
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $now = microtime(true);
            if ($now >= $deadline) {
                return false;
            }

            $this->drain();
            $seen = $this->resultCount();
            if ($seen >= $count) {
                return true;
            }

            $running = $this->isRunning();
            if (!$running) {
                $this->drain();

                return $this->resultCount() >= $count;
            }
        }
    }

    /** @return int|null the exit code, or null when the process was still running at the deadline */
    public function waitForExit(float $timeout = 60.0): ?int
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $now = microtime(true);
            if ($now >= $deadline) {
                return null;
            }

            $this->drain();
            $running = $this->isRunning();
            if (!$running) {
                $this->drain();

                return $this->exitCode;
            }
        }
    }

    /** @return bool false once the process has been reaped */
    public function isRunning(): bool
    {
        if ($this->exitCode !== null) {
            return false;
        }

        $status = proc_get_status($this->process);
        if ($status['running']) {
            return true;
        }

        $this->exitCode = $status['exitcode'];

        return false;
    }

    /** @return int how many turn boundaries have arrived so far */
    public function resultCount(): int
    {
        $count = 0;
        foreach ($this->lines() as $line) {
            if (!str_contains($line, '"type":"result"')) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /** @return list<string> the stdout lines read so far, blank lines dropped */
    public function lines(): array
    {
        return array_values(array_filter(explode("\n", $this->out), static fn(string $l): bool => $l !== ''));
    }

    /** @return list<array<array-key, mixed>> the stdout lines that are JSON objects */
    public function decodedLines(): array
    {
        $decoded = [];
        foreach ($this->lines() as $line) {
            $object = Json::decode($line);
            if ($object === null) {
                continue;
            }

            $decoded[] = $object;
        }

        return $decoded;
    }

    /** @return list<string> the `type` of every decoded stdout line, in order */
    public function eventTypes(): array
    {
        $types = [];
        foreach ($this->decodedLines() as $line) {
            $types[] = Json::text($line, 'type') ?? '';
        }

        return $types;
    }

    /** @return string everything the process has written to stderr so far */
    public function stderr(): string
    {
        $this->drain();

        return $this->err;
    }

    /** Kills the process and closes its pipes; safe to call more than once. */
    public function stop(): void
    {
        $alive = is_resource($this->process) && $this->isRunning();
        if ($alive) {
            proc_terminate($this->process);
            $this->waitForExit(5.0);
        }

        foreach ([$this->stdin, $this->stdout, $this->errors] as $pipe) {
            if (!is_resource($pipe)) {
                continue;
            }

            fclose($pipe);
        }
    }

    /** Moves whatever the process has written into the buffers, without ever blocking on it. */
    private function drain(): void
    {
        $read = array_values(array_filter([$this->stdout, $this->errors], is_resource(...)));
        if ($read === []) {
            usleep(1_000);

            return;
        }

        $write = null;
        $except = null;
        $ready = stream_select($read, $write, $except, seconds: 0, microseconds: 20_000);
        if ($ready === false) {
            return;
        }

        foreach ($read as $stream) {
            $chunk = fread($stream, length: 65_536);
            if ($chunk === false || $chunk === '') {
                continue;
            }

            if ($stream === $this->stdout) {
                $this->out .= $chunk;
                continue;
            }

            $this->err .= $chunk;
        }
    }
}
