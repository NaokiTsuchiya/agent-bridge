<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Runner;

use NaokiTsuchiya\AgentBridge\Thread\ThreadDerivation;
use NaokiTsuchiya\AgentBridge\Thread\ThreadId;

use function implode;
use function json_encode;

/**
 * How a `claude` is asked to run, and how a turn is handed to one that is already running.
 *
 * Both are the wire protocol rather than execution: the flags below are what makes the binary
 * stream its answer line by line, and the one line {@see prompt()} builds is the only shape the
 * binary accepts on stdin. They are kept together because a change to one is usually a change
 * to the other, and apart from {@see PersistentCliRunner}, which is about processes.
 *
 * @api
 */
final readonly class ClaudeCliCommand
{
    /** @param ClaudeCliSettings $settings which binary to run, and with which permissions */
    public function __construct(
        private ClaudeCliSettings $settings,
    ) {}

    /**
     * @param HistoryStart $start how this process should relate to the thread's history
     *
     * @return list<string> the binary and its arguments, to be run without a shell
     */
    public function arguments(ThreadId $thread, HistoryStart $start): array
    {
        return [
            $this->settings->binary,
            '-p',
            '--input-format',
            'stream-json',
            '--output-format',
            'stream-json',
            '--verbose',
            // Without this the reply arrives in one piece at the end of the turn.
            '--include-partial-messages',
            '--allowedTools',
            implode(',', $this->settings->allowedTools),
            $start->value,
            ThreadDerivation::sessionId($thread),
        ];
    }

    /** @return string one line of `stream-json` input, newline included */
    public static function prompt(string $prompt): string
    {
        $line = json_encode([
            'type' => 'user',
            'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => $prompt]]],
        ]);

        return ($line === false ? '{}' : $line) . "\n";
    }
}
