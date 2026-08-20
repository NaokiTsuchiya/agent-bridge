<?php

declare(strict_types=1);

namespace NaokiTsuchiya\AgentBridge\Slack;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;

/**
 * The runbook a person follows against a real workspace.
 *
 * Everything this front end can be asked without a token is asked elsewhere in this directory. What
 * is left — that a mention comes back in the same thread, that five exchanges keep their context,
 * that two threads do not cross, that a restart lands in the same worktree — can only be seen by
 * somebody doing it. This does not do it; it makes sure the instructions for doing it are all
 * still there, so that a step cannot quietly go missing.
 *
 * @internal
 */
final class SlackAdapterDocTest extends TestCase
{
    /**
     * @param string $step a heading or a phrase the runbook has to carry
     */
    #[DataProvider('requiredRunbookSteps')]
    #[Test]
    public function documentsEveryManualSmokeStep(string $step): void
    {
        $runbook = file_get_contents(dirname(__DIR__, levels: 2) . '/docs/slack-adapter.md');
        self::assertIsString($runbook, 'docs/slack-adapter.md is missing.');

        self::assertStringContainsString($step, $runbook);
    }

    /** @return iterable<string, array{string}> */
    public static function requiredRunbookSteps(): iterable
    {
        yield 'which status method was adopted' => ['## 3. 状態表示にどちらを採用したか'];
        yield 'the scopes the app needs' => ['## 5. Slack アプリの設定'];
        yield 'the events it subscribes to' => ['app_mention'];
        yield 'the bot token scope' => ['chat:write'];
        yield 'the streaming methods that scope covers' => [
            '`chat.startStream` / `chat.appendStream` / `chat.stopStream`',
        ];
        yield 'that no assistant scope is needed' => ['**`assistant:write` は要らない。**'];
        yield 'a mention answered in the same thread' => ['## 7. メンションに応答が同じスレッドで逐次現れる'];
        yield 'the reply appearing as it is written' => ['**応答が逐次現れること**'];
        yield 'what a tool call looks like while it runs' => ['**ツール実行中の表示**'];
        yield 'five exchanges in one thread' => ['## 8. 同一スレッド 5 往復で文脈が保たれる'];
        yield 'two threads at once' => ['## 9. 別スレッド 2 本を同時に走らせて混線しない'];
        yield 'two worktrees edited in parallel' => ['## 10. 別スレッド 2 本が別 worktree で並行編集して衝突しない'];
        yield 'a restart' => ['## 11. サーバ再起動後も同じ worktree に着き文脈が継続する'];
        yield 'a long task' => ['## 12. 長時間タスク (5 分以上)'];
        yield 'a stream that survives one' => ['**5 分以上かかってもストリームが切れない**こと'];
        yield 'the same thread id from both front ends' => ['## 13. 同じ ThreadId を CLI と Slack の両方から使う'];
        yield 'that a person runs it' => ['自動判定の対象外'];
        yield 'the host the API is reached at' => ['`' . SlackApiEndpointProvider::HOST_VARIABLE . '`'];
        yield 'the port it is reached on' => ['`' . SlackApiEndpointProvider::PORT_VARIABLE . '`'];
        yield 'the host a deployment gets without saying anything' => ['(既定: `slack.com`)'];
        yield 'the port it gets without saying anything' => ['(既定: `443`)'];
    }
}
