<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use function array_shift;
use function array_values;
use function fclose;
use function fread;
use function is_resource;
use function microtime;
use function stream_select;
use function substr;

/**
 * The two streams a child writes to, read as a sequence of whole lines.
 *
 * Both are watched together because neither may be left unread: the diagnostics stream fills up
 * like any other pipe, and a child whose write to it blocks stops answering altogether. What
 * arrives there is kept aside instead of being handed on — it is not part of the conversation,
 * but it is what a failure can be explained with.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 *
 * @api
 */
final class ProcessOutput
{
    /** Big enough that a turn's output rarely needs many reads, small enough to stay responsive. */
    private const int READ_SIZE = 65_536;

    /** How much of the diagnostics to keep, counted from the end. */
    private const int ERROR_TAIL = 2_000;

    /** Assembles the lines, since a read ends wherever the kernel had data. */
    private LineBuffer $buffer;

    /** @var list<string> lines already assembled but not handed out yet */
    private array $lines = [];

    /** The tail of what the child said about itself, kept for a failure message. */
    private string $errorText = '';

    /** Whether the child's output has reached its end, which a deadline running out is not. */
    private bool $finished = false;

    /**
     * @param resource|null $output what the child answers with
     * @param resource|null $errors what the child complains with
     */
    public function __construct(
        private mixed $output,
        private mixed $errors,
    ) {
        $this->buffer = new LineBuffer();
    }

    /**
     * @param float|null $seconds how long to wait for one, or null to wait for as long as it takes
     *
     * @return string|null the next whole line, or null when the wait ran out or the output ended.
     *                     {@see ended()} is what tells the two apart
     */
    public function nextLine(?float $seconds = null): ?string
    {
        $deadline = $seconds === null ? null : microtime(true) + $seconds;
        while (true) {
            $line = array_shift($this->lines);
            if ($line !== null) {
                return $line;
            }

            $chunk = $this->read($deadline);
            if ($chunk === null) {
                return null;
            }

            $this->lines = $this->buffer->append($chunk);
        }
    }

    /** @return bool true once the child's output has ended, which no deadline can cause */
    public function ended(): bool
    {
        return $this->finished;
    }

    /** @return string at most {@see self::ERROR_TAIL} bytes of what the child complained about */
    public function errorTail(): string
    {
        return $this->errorText;
    }

    /** Lets go of both streams, whether or not they had reached their end. */
    public function close(): void
    {
        foreach ([$this->output, $this->errors] as $stream) {
            if (!is_resource($stream)) {
                continue;
            }

            fclose($stream);
        }

        $this->output = null;
        $this->errors = null;
    }

    /**
     * @param float|null $deadline when to give up, as an absolute {@see microtime} value
     *
     * @return string|null what the child answered, or null when the deadline passed or it will
     *                     answer nothing more
     */
    private function read(?float $deadline): ?string
    {
        while (true) {
            $streams = $this->open();
            if ($streams === []) {
                $this->finished = true;

                return null;
            }

            $write = null;
            $except = null;
            $budget = self::budget($deadline);
            $ready = stream_select($streams, $write, $except, $budget[0], $budget[1]);
            if ($ready === false) {
                $this->finished = true;

                return null;
            }

            // Nothing was ready before the deadline. Measured: a child that is merely slow shows
            // up here, while one whose output has ended shows up as a readable stream that reads
            // empty — so this return leaves `finished` alone and the caller can tell them apart.
            if ($ready === 0) {
                return null;
            }

            // stream_select rewrites the array in place, keeping only what is ready and
            // renumbering nothing; array_values puts it back into the shape drain() expects.
            $chunk = $this->drain(array_values($streams));
            if ($chunk !== '') {
                return $chunk;
            }

            if (!is_resource($this->output)) {
                $this->finished = true;

                return null;
            }
        }
    }

    /**
     * @param float|null $deadline as an absolute {@see microtime} value, or null for no deadline
     *
     * @return array{0: int|null, 1: int} what is left of the wait, in the two units
     *                                    {@see stream_select} takes it in. A null first element
     *                                    is how that function is told to wait indefinitely
     */
    private static function budget(?float $deadline): array
    {
        if ($deadline === null) {
            return [null, 0];
        }

        $left = $deadline - microtime(true);
        if ($left <= 0.0) {
            return [0, 0];
        }

        $seconds = (int) $left;

        return [$seconds, (int) (($left - $seconds) * 1_000_000)];
    }

    /**
     * @param list<resource> $streams whichever streams had something to say
     *
     * @return string what came from the answering stream; the other is kept aside
     */
    private function drain(array $streams): string
    {
        $chunk = '';
        foreach ($streams as $stream) {
            $isOutput = $stream === $this->output;
            $read = fread($stream, length: self::READ_SIZE);
            if ($read === false || $read === '') {
                $this->forget($stream);
                continue;
            }

            if ($isOutput) {
                $chunk .= $read;
                continue;
            }

            $this->errorText = substr($this->errorText . $read, offset: -self::ERROR_TAIL);
        }

        return $chunk;
    }

    /**
     * Closes a stream that has reached its end, so that it stops being selected forever.
     *
     * @param resource $stream
     */
    private function forget(mixed $stream): void
    {
        fclose($stream);

        if ($stream === $this->output) {
            $this->output = null;

            return;
        }

        $this->errors = null;
    }

    /** @return list<resource> the streams that are still worth reading */
    private function open(): array
    {
        $streams = [];
        foreach ([$this->output, $this->errors] as $stream) {
            if (!is_resource($stream)) {
                continue;
            }

            $streams[] = $stream;
        }

        return $streams;
    }
}
