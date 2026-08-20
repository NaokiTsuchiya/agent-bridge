<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Process;

use Throwable;

use function fwrite;

/**
 * Where everything that is not an answer goes.
 *
 * @api
 */
final class ErrorStream
{
    /** @param resource $stream the stream a refusal is written to */
    public function __construct(
        private mixed $stream,
    ) {}

    /** Says why nothing more will happen. */
    public function explain(Throwable $failure): void
    {
        $reason = $failure->getMessage();
        $this->complain("{$reason}\n");
    }

    /** Writes text as it stands, ending no line of its own. */
    public function complain(string $text): void
    {
        fwrite($this->stream, $text);
    }
}
