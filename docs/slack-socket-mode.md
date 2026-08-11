# Slack Socket Mode — 手動スモークテスト

このリポジトリの Socket Mode クライアントは、実 WebSocket と実トークンを要求しない範囲まで自動テストで覆ってある (`tests/Slack/`)。**覆えないのは 3 つの I/O だけ** — `apps.connections.open` の HTTP 呼び出し、WSS への upgrade、フレームの送受信そのもの。この 3 つを人間が 1 回確かめるための手順書である。

自動判定の対象外。CI では回さない。Slack アプリを作り直したとき、Slack 側の仕様が変わったとき、`src/Slack/SwooleSocketModeConnector.php` か `src/Slack/SwooleSocketModeConnection.php` を触ったときに、この手順を人手で通す。

所要 15 分程度。捨ててよいワークスペース (または自分の個人ワークスペース) を 1 つ用意すること。

---

## 1. Socket Mode を有効にする

1. <https://api.slack.com/apps> で **Create New App** → **From scratch**。名前は任意、開発用ワークスペースを選ぶ。
2. 左メニュー **Settings → Socket Mode** を開き、**Enable Socket Mode** をオンにする。
3. 有効にすると、そのアプリはイベントを **WebSocket 経由でしか受け取らなくなる** (HTTP の Request URL は使われない)。公開エンドポイントを用意しなくてよいのがこの方式を選んだ理由 (`docs/poc-design.md` 3 章)。

確認: Socket Mode のページに "Socket Mode is enabled" と出ていること。

## 2. App-level token を作る

1. 左メニュー **Settings → Basic Information** → **App-Level Tokens** → **Generate Token and Scopes**。
2. 名前は `agent-bridge-dev` など任意。
3. **スコープに `connections:write` を追加する。** これが Socket Mode 接続を開く権限そのもので、無いと `apps.connections.open` が失敗する。イベントの購読やメッセージ送信に要るスコープは bot token 側の話であり、この token には要らない。
4. **Generate** を押すと `xapp-` で始まる文字列が 1 度だけ表示される。

環境変数へ入れる (履歴に残したくなければ先頭に空白を置く):

```bash
export SLACK_APP_TOKEN='xapp-…'
```

**この値をリポジトリに書かない。** `src/` に実トークンが入っていないことは `tests/Slack/SocketModeSourceTest.php` が毎回確かめている。

## 3. イベントを購読する (5 章の準備)

1. **Features → Event Subscriptions** をオンにする (Socket Mode が有効なら Request URL の入力欄は出ない)。
2. **Subscribe to bot events** に `app_mention` を追加する。
3. **Features → OAuth & Permissions** の **Bot Token Scopes** に `app_mentions:read` が入っていることを確認する。
4. **Install to Workspace** でワークスペースへ入れ、テスト用チャンネルにアプリを招待する (`/invite @<アプリ名>`)。

> スコープを後から足したときは、再インストールしないと反映されない。

## 4. 実ワークスペースへ接続する

`SwooleSocketModeConnector` を 1 回だけ動かす小さなスクリプトを、リポジトリ外の一時ファイルとして書く (常駐プロセスの起動は #14 以降の仕事なので、ここでは手で組む):

```php
<?php
// /tmp/socket-mode-smoke.php
require __DIR__ . '/path/to/agent-bridge/vendor/autoload.php';

use NaokiTsuchiya\AgentBridge\Slack\{Backoff, EnvelopeLog, CoroutineSleeper, FrameRouter,
    MtRandomSource, ReconnectDelay, SlackAppTokenFactory, SocketModeClient, StderrSocketModeLogger,
    SwooleHttpClientFactory, SwooleSocketModeConnector};
use Swoole\Coroutine\Channel;

use function Swoole\Coroutine\run;

run(static function (): void {
    $logger = new StderrSocketModeLogger();
    $envelopes = new Channel(16);

    // 後段はまだ無い (#14) ので、押し込まれた payload をこのコルーチンで捨てながら読む。
    go(static function () use ($envelopes): void {
        while (true) {
            var_dump($envelopes->pop());
        }
    });

    new SocketModeClient(
        new SwooleSocketModeConnector(SlackAppTokenFactory::fromEnvironment(), new SwooleHttpClientFactory()),
        new FrameRouter($envelopes, new EnvelopeLog(), $logger),
        new ReconnectDelay(new Backoff(new MtRandomSource()), new CoroutineSleeper()),
        $logger,
    )->run();
});
```

```bash
php /tmp/socket-mode-smoke.php
```

確認すること:

- 数秒以内に `[socket-mode] connected` が出る (Slack の `hello` フレームを受けた証拠)。
- **トークンが出力に現れない。**
- `SLACK_APP_TOKEN` を空にして起動すると、接続を試みる前に `SLACK_APP_TOKEN is not set…` で落ちる。
- 別種のトークン (`xoxb-…`) を入れて起動すると、`xapp-` を要求するメッセージで落ちる。

つながらないときは、`cannot connect: apps.connections.open failed: <error>` の `<error>` を見る (`invalid_auth` = トークンが違う、`not_allowed_token_type` = app-level token でない)。

## 5. イベントの到達と ack を確かめる

1. 3 章で招待したチャンネルで `@<アプリ名> ping` と発言する。
2. スクリプトの出力に、`event` を含む payload が `var_dump` されること。
3. **ack が 3 秒以内に返っていること**は、次で確かめる: 同じメッセージが**もう一度届かない**こと。Slack は ack を受け取れなかった envelope を再送するので、数分待って再送が来なければ ack は届いている。
4. 再送が来た場合 (`acknowledged … again without handing it on` がログに出る場合) は、重複排除が効いていて後段には 1 回しか流れていないことも同時に確認できる。

## 6. 手動で切断して再接続を確かめる

次のどれかで接続を切り、**自動で再接続する**ことを確かめる:

- Wi-Fi を 10 秒ほど切って戻す (`connection lost: …` → `connected`)。
- Slack 側から切らせる: api.slack.com のアプリ設定で Socket Mode を一度オフ→オンにする (`Slack asked for a reconnect (…)` → `connected`)。
- そのまま放置する。Slack は数時間ごとに接続を張り替え、切断の 10 秒前に `disconnect` の警告フレームを送ってくる。

確認すること:

- 再接続が**指数バックオフで**行われる (連続で失敗させると、待ち時間が 1 秒、2 秒、4 秒… と伸びて上限で頭打ちになる。ネットワークを切ったままにすると観察できる)。
- 再接続後に、再びメッセージを送ると 5 章と同じように届くこと。
- 何時間か放置しても、無音タイムアウト (既定 60 秒) で毎回切れたりしないこと — Slack の keepalive が届いていれば無音にはならない。

---

## 付録: この手順が確かめているもの

| 手順 | 自動テストでは触れない部分 |
|---|---|
| 4 | `apps.connections.open` に正しいメソッド・ヘッダで到達しているか、返った URL で upgrade できるか |
| 5 | 送った ack を Slack が受け取っているか (再送が来ないことでしか分からない) |
| 6 | keepalive の ping に pong を返せているか、切断後に張り直せるか |

応答本文の解釈 (`ConnectionOpenResponse`)、URL の分解 (`WebsocketEndpoint`)、受信結果の分類 (`ReceivedFrame`)、フレームの分岐・ack・重複排除・バックオフ (`FrameRouter` / `SocketModeClient` / `EnvelopeLog` / `Backoff`) は、いずれも `tests/Slack/` が実接続なしで覆っている。**この手順書で人が見るのは、その外側の I/O だけである。**
