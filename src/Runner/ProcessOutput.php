<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use function array_shift;
use function array_values;
use function fclose;
use function fread;
use function is_resource;
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

    /** @return string|null the next whole line, or null once the child's output has ended */
    public function nextLine(): ?string
    {
        while (true) {
            $line = array_shift($this->lines);
            if ($line !== null) {
                return $line;
            }

            $chunk = $this->read();
            if ($chunk === null) {
                return null;
            }

            $this->lines = $this->buffer->append($chunk);
        }
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

    /** @return string|null what the child answered, or null once it will answer nothing more */
    private function read(): ?string
    {
        while (true) {
            $streams = $this->open();
            if ($streams === []) {
                return null;
            }

            $write = null;
            $except = null;
            $ready = stream_select($streams, $write, $except, seconds: null);
            if ($ready === false) {
                return null;
            }

            // stream_select rewrites the array in place, keeping only what is ready and
            // renumbering nothing; array_values puts it back into the shape drain() expects.
            $chunk = $this->drain(array_values($streams));
            if ($chunk !== '') {
                return $chunk;
            }

            if (!is_resource($this->output)) {
                return null;
            }
        }
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
