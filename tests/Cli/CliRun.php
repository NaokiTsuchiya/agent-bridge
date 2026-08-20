<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Cli;

/** What one run of the command line front end left behind. */
final readonly class CliRun
{
    /**
     * @param int          $code   the exit code the process ended with
     * @param string       $output what it wrote to standard output, blank lines dropped
     * @param list<string> $lines  the same output line by line, for assertions about a line
     * @param string       $errors what it wrote to standard error
     */
    public function __construct(
        public int $code,
        public string $output,
        public array $lines,
        public string $errors,
    ) {}
}
