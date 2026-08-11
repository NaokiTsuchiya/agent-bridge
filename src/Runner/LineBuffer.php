<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use function array_pop;
use function explode;
use function trim;

/**
 * Turns a stream of arbitrary chunks back into whole lines.
 *
 * A read from a pipe ends wherever the kernel had data, which is nowhere in particular: a single
 * JSON line routinely arrives in two or three pieces, and two lines routinely arrive in one. PHP's
 * line-oriented reads would hide that, but they also block until a line is complete, which a
 * caller that has to stay responsive cannot afford — so the splitting is done here instead.
 *
 * @api
 */
final class LineBuffer
{
    /** What has arrived since the last newline, which is not a line until the newline shows up. */
    private string $rest = '';

    /**
     * @param string $chunk bytes exactly as they were read, possibly ending mid-line
     *
     * @return list<string> the lines completed by this chunk, blank ones dropped
     */
    public function append(string $chunk): array
    {
        $parts = explode("\n", $this->rest . $chunk);
        // The last element is whatever followed the final newline: the empty string when the
        // chunk ended on one, and the beginning of the next line when it did not.
        $this->rest = array_pop($parts);

        $lines = [];
        foreach ($parts as $part) {
            $line = trim($part);
            if ($line === '') {
                continue;
            }

            $lines[] = $line;
        }

        return $lines;
    }
}
