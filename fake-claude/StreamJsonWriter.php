<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\FakeClaude;

use function fwrite;
use function json_encode;
use function md5;
use function str_split;
use function substr;
use function uniqid;

use const STDOUT;

/**
 * The stdout half of the wire protocol: the lines a turn is made of.
 *
 * Shapes follow what Claude Code 2.1.223 was measured emitting, trimmed to the keys a consumer
 * reads. Two of them are not decoration:
 * - `stream_event` lines appear only when the caller passed `--include-partial-messages`, so a
 *   consumer that forgets the flag gets whole messages and no deltas at all;
 * - the deltas of a turn concatenate to exactly the `assistant` text that follows them, which is
 *   what lets a frontend stream the deltas and then trust the whole message.
 */
final readonly class StreamJsonWriter
{
    /** @param bool $includePartialMessages whether the caller passed `--include-partial-messages` */
    public function __construct(
        private string $sessionId,
        private string $cwd,
        private bool $includePartialMessages,
    ) {}

    /** Re-sent at the start of every turn, exactly as the real CLI re-sends it. */
    public function init(): void
    {
        $this->emit([
            'type' => 'system',
            'subtype' => 'init',
            'cwd' => $this->cwd,
            'session_id' => $this->sessionId,
            'tools' => [],
            'mcp_servers' => [],
            'model' => 'fake',
            'permissionMode' => 'default',
            'claude_code_version' => 'fake',
            'uuid' => self::uuid(),
        ]);
    }

    /** The reply as deltas, or nothing at all when the caller did not ask for partial messages. */
    public function partialMessages(string $reply): void
    {
        if (!$this->includePartialMessages) {
            return;
        }

        $this->event(['type' => 'message_start', 'message' => ['role' => 'assistant', 'content' => []]]);
        $this->event([
            'type' => 'content_block_start',
            'index' => 0,
            'content_block' => ['type' => 'text', 'text' => ''],
        ]);
        foreach (str_split($reply, length: 3) as $chunk) {
            $this->event([
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'text_delta', 'text' => $chunk],
            ]);
        }

        $this->event(['type' => 'content_block_stop', 'index' => 0]);
        $this->event(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']]);
        $this->event(['type' => 'message_stop']);
    }

    /** @param ToolDirective|null $tool null on a turn that calls no tool, which is the default */
    public function toolUse(?ToolDirective $tool): void
    {
        if ($tool === null) {
            return;
        }

        $this->emit([
            'type' => 'assistant',
            'message' => [
                'role' => 'assistant',
                'content' => [['type' => 'tool_use', 'id' => $tool->id, 'name' => $tool->name, 'input' => []]],
            ],
            'session_id' => $this->sessionId,
        ]);
        $this->emit([
            'type' => 'user',
            'message' => [
                'role' => 'user',
                'content' => [[
                    'tool_use_id' => $tool->id,
                    'type' => 'tool_result',
                    'content' => $tool->result,
                    'is_error' => false,
                ]],
            ],
            'session_id' => $this->sessionId,
        ]);
    }

    /** The whole message, which the deltas before it add up to. */
    public function assistantText(string $reply): void
    {
        $this->emit([
            'type' => 'assistant',
            'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => $reply]]],
            'session_id' => $this->sessionId,
        ]);
    }

    /**
     * The turn boundary: a consumer knows a turn is over when, and only when, this line arrives.
     *
     * @param int $turns 0 on the result that reports a session which could not be opened at all
     *
     * @mago-expect lint:no-boolean-flag-parameter
     */
    public function result(string $reply, bool $isError, int $turns): void
    {
        $this->emit([
            'type' => 'result',
            'subtype' => $isError ? 'error_during_execution' : 'success',
            'is_error' => $isError,
            'duration_ms' => 0,
            'num_turns' => $turns,
            'session_id' => $this->sessionId,
            'result' => $reply,
            'total_cost_usd' => 0,
            'permission_denials' => [],
            'uuid' => self::uuid(),
        ]);
    }

    /** @param array<string, mixed> $event */
    private function event(array $event): void
    {
        $this->emit(['type' => 'stream_event', 'event' => $event, 'session_id' => $this->sessionId]);
    }

    /** @param array<string, mixed> $line */
    private function emit(array $line): void
    {
        $json = json_encode($line);
        if ($json === false) {
            return;
        }

        fwrite(STDOUT, "{$json}\n");
    }

    /** A v4-shaped identifier; the real CLI puts one on every line, and nothing reads it. */
    private static function uuid(): string
    {
        $hex = md5(uniqid('', more_entropy: true));
        $a = substr($hex, offset: 0, length: 8);
        $b = substr($hex, offset: 8, length: 4);
        $c = substr($hex, offset: 13, length: 3);
        $d = substr($hex, offset: 17, length: 3);
        $e = substr($hex, offset: 20, length: 12);

        return "{$a}-{$b}-4{$c}-8{$d}-{$e}";
    }
}
