<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Git;

/**
 * The seam every git invocation goes through, so that callers can be driven without a real repository.
 *
 * @api
 */
interface GitInterface
{
    /**
     * Runs git inside the given repository and waits for it to finish.
     *
     * @param list<string> $arguments
     *
     * @return array{int, string} exit code and the combined output, stderr included
     */
    public function run(string $repository, array $arguments): array;
}
