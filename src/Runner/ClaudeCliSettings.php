<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

/**
 * Everything about running the `claude` binary that is a deployment decision rather than code.
 *
 * This is the one place the defaults live. {@see PersistentCliRunner} holds none of them: an
 * allow-list baked into the runner would be a permission decision hidden inside an execution
 * detail, and a binary name baked in would make the runner untestable without a real Claude Code.
 *
 * @api
 */
final readonly class ClaudeCliSettings
{
    /**
     * Tools that can only look, never change anything, which is the conservative starting point.
     *
     * @var list<string>
     */
    public const array READ_ONLY_TOOLS = ['Read', 'Glob', 'Grep'];

    /**
     * @param string       $binary            the executable, resolved through `PATH` when it is a bare name
     * @param list<string> $allowedTools      passed to `--allowedTools`; nothing outside it may run
     * @param float        $closeGraceSeconds how long {@see PersistentCliRunner::close()} waits for a
     *                                        process to end on its own before it is terminated. A turn
     *                                        in flight is what makes this take time, so the default is
     *                                        long enough for one to finish
     */
    public function __construct(
        public string $binary = 'claude',
        public array $allowedTools = self::READ_ONLY_TOOLS,
        public float $closeGraceSeconds = 10.0,
    ) {}
}
