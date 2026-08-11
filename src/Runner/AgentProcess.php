<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use Swoole\Coroutine;

use function fclose;
use function fwrite;
use function is_resource;
use function microtime;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function restore_error_handler;
use function set_error_handler;
use function usleep;

/**
 * One child process of the agent binary, with its pipes.
 *
 * Started with {@see proc_open} rather than `Swoole\Process`, which cannot be used here at all:
 * `Swoole\Process::start()` throws `must be forked outside the coroutine` (measured on Swoole
 * 6.2.0), and this class exists to be driven from inside `Coroutine\run()`. `proc_open` is
 * coroutine-aware once `SWOOLE_HOOK_PROC` is on, which {@see PersistentCliRunner} turns on.
 *
 * @mago-expect lint:too-many-methods
 *
 * @api
 */
final class AgentProcess
{
    /** How long a poll for the child's end waits between attempts. */
    private const float POLL_SECONDS = 0.005;

    /** The same interval, in the unit the coroutine-less path wants it in. */
    private const int POLL_MICROSECONDS = 5_000;

    /** Kept because `proc_get_status()` reports the real code once and -1 from then on. */
    private ?int $exitCode = null;

    /** Whether anything read from this child has reached the caller, set from the outside. */
    public bool $emitted = false;

    /** Whether a turn is being answered right now, kept by {@see ProcessPool}. */
    public bool $busy = false;

    /** When a turn on this child last began or ended, kept by {@see ProcessPool}. */
    public float $lastUsedAt;

    /**
     * @param resource      $handle the process itself
     * @param resource|null $input  what the child reads its turns from
     * @param ProcessOutput $output what the child answers on
     * @param int           $pid    the child's own pid, which stays readable after it has ended
     * @param HistoryStart  $start  how this child was asked to relate to the thread's history
     */
    private function __construct(
        private mixed $handle,
        private mixed $input,
        private ProcessOutput $output,
        public int $pid,
        public HistoryStart $start,
    ) {
        $this->lastUsedAt = microtime(true);
    }

    /**
     * @param list<string> $command the binary and its arguments, run without a shell
     * @param string       $cwd     the directory the child starts in
     *
     * @return self|null null when the child could not be started at all
     */
    public static function start(array $command, string $cwd, HistoryStart $start): ?self
    {
        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $pipes = [];
        $handle = proc_open($command, $descriptors, $pipes, $cwd === '' ? null : $cwd);
        if (!is_resource($handle)) {
            return null;
        }

        /** @var array{0: resource, 1: resource, 2: resource} $pipes */
        $status = proc_get_status($handle);

        return new self($handle, $pipes[0], new ProcessOutput($pipes[1], $pipes[2]), $status['pid'], $start);
    }

    /**
     * Hands the child one line of input.
     *
     * A child that has already died leaves nothing to write to, and the failure is not reported
     * here: the reading side meets the same end of input a moment later with the context to say
     * what it means, while a failure raised here would have to guess. The diagnostic PHP raises
     * over a broken pipe is dropped along with it — the suite is configured to fail on those, and
     * this one carries nothing the caller can act on.
     */
    public function write(string $line): void
    {
        if (!is_resource($this->input)) {
            return;
        }

        set_error_handler(static fn(): bool => true);

        try {
            fwrite($this->input, $line);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param float|null $seconds how long to wait for one, or null to wait for as long as it takes
     *
     * @return string|null the next whole line the child wrote, or null when the wait ran out or
     *                     it wrote its last. {@see outputEnded()} tells the two apart
     */
    public function nextLine(?float $seconds = null): ?string
    {
        return $this->output->nextLine($seconds);
    }

    /** @return bool true once the child has written its last line; a deadline never causes it */
    public function outputEnded(): bool
    {
        return $this->output->ended();
    }

    /** @return bool false once the child has ended and its code has been collected */
    public function isRunning(): bool
    {
        if ($this->exitCode !== null) {
            return false;
        }

        $status = proc_get_status($this->handle);
        if ($status['running']) {
            return true;
        }

        $this->exitCode = $status['exitcode'];

        return false;
    }

    /**
     * Waits for the child to end, without holding up anything else that is running.
     *
     * Polling rather than a blocking wait is the whole point: a blocking wait inside
     * `Coroutine\run()` stops every other coroutine until the child is gone.
     *
     * @return bool false when the child was still running at the deadline
     */
    public function awaitExit(float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (true) {
            $running = $this->isRunning();
            if (!$running) {
                return true;
            }

            $now = microtime(true);
            if ($now >= $deadline) {
                return false;
            }

            self::pause();
        }
    }

    /** End of input, which is how the child is asked to finish what it is doing and stop. */
    public function closeInput(): void
    {
        if (!is_resource($this->input)) {
            return;
        }

        fclose($this->input);
        $this->input = null;
    }

    /** Asks the operating system to end a child that would not end on its own. */
    public function terminate(): void
    {
        $running = $this->isRunning();
        if (!$running) {
            return;
        }

        proc_terminate($this->handle);
    }

    /** Closes the pipes and collects the child, so that nothing is left behind. */
    public function release(): void
    {
        $this->closeInput();
        $this->output->close();
        $this->terminate();
        proc_close($this->handle);
    }

    /** @return string what to tell the caller when the child ended in the middle of a turn */
    public function failureMessage(): string
    {
        $this->isRunning();
        $code = $this->exitCode ?? -1;
        $tail = $this->output->errorTail();
        $reason = $tail === '' ? '' : " {$tail}";

        return "The agent ended before finishing the turn (exit code {$code}).{$reason}";
    }

    /** Waits a moment, giving up the processor to other coroutines when there are any. */
    private static function pause(): void
    {
        $inCoroutine = Coroutine::getCid() > 0;
        if ($inCoroutine) {
            Coroutine::sleep(self::POLL_SECONDS);

            return;
        }

        usleep(self::POLL_MICROSECONDS);
    }
}
