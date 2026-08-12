# Slack アダプタ (`chat.postMessage` 版)

**ステータス:** 実装済み (issue #14)
**対象:** `bin/agent-bridge-slack` / `src/Slack/` / `src/Di/SlackModule.php` / `src/Di/SlackContext.php`

7 章以降は**人が実 Slack ワークスペースに対して行う手動スモークテスト**で、**自動判定の対象外**。CI では回さない。

---

## 1. これは何か

`ChatIngress` / `ChatEgress` / `StreamHandle` (#10) の 2 つ目の実装。#13 の Socket Mode クライアントが受けた envelope を、CLI アダプタ (#11) と同じパイプラインに流し、答えをスレッド返信として返す。

**出力はこの段では `chat.postMessage` 1 本。** 差分はターン中ためておき、ターンが終わったときに 1 通として投稿する。ストリーミング API (`chat.startStream` / `appendStream` / `stopStream`) への差し替えは #15 で、そのとき**実行層より下は 1 行も変わらない**ことが確かめられるように、この段を分けてある。

`src/Di/SlackModule.php` がその主張そのものになっている: `AppModule` を `install()` したうえで `ChatEgress` を束縛し直すのが差し替えの全部で、パイプラインから下は CLI が使うものと同一である。

## 2. ThreadId と、無視するもの

ThreadId は `slack:` + **スレッドの `ts`**。

| メッセージ | ThreadId |
|---|---|
| `thread_ts` を持つ | `slack:` + `thread_ts` |
| `thread_ts` を持たない (スレッドの 1 通目) | `slack:` + そのメッセージの `ts` |

2 つ目のメッセージ以降は `thread_ts` に 1 通目の `ts` が入るので、**同じスレッドのやり取りはすべて同じ ThreadId** に解決される。session_id と worktree はそこから導出するだけで (#3)、この アダプタは導出を一切再実装していない — `IncomingMessage` に `platform` と `nativeId` を文字列で渡すだけである。

無視するもの (`SlackMessage::from()` が `null` を返すもの):

| 何を | なぜ |
|---|---|
| `type` が `event_callback` でない envelope | イベント配信ではない |
| `event.type` が `app_mention` / `message` 以外 (`reaction_added` など) | 質問ではない |
| `subtype` を持つ message (`message_changed` / `channel_join` …) | メッセージ**についての**通知であって、メッセージではない |
| `bot_id` を持つメッセージ | アプリの投稿。自分の返信を自分で答えることになる |
| `user` が自 app の bot user ID と一致するメッセージ | 同上。bot user として投稿された場合はこちらで捕まえる |
| `channel` / `ts` を持たないメッセージ | 返す先も、スレッドを名乗る値も無い |

**bot 判定を `bot_id` と `user` の 2 つで行う**のは要求どおりで、どちらか一方では取りこぼす経路がある。`user` の比較は完全一致で、`U0BOT` と `U0BOTX` は別人として扱う (`tests/Slack/SlackMessageTest.php`)。

## 3. 状態表示にどちらを採用したか

**採用: `assistant.threads.setStatus` を呼び、Slack が断ったら一時メッセージを投稿する。**

`docs/poc-design.md` 4.5 に記録したとおり、`assistant.threads.setStatus` は assistant スレッド向けの API で、**通常チャンネルのスレッドで機能する保証が公式ドキュメントから読み取れない** (2026-08 時点)。実ワークスペースに当てずにどちらか一方へ賭けることはできないので、どちらも実装して**応答に判断させる**:

1. 受付直後に `assistant.threads.setStatus` を呼ぶ (`channel_id` / `thread_ts` / `status`)。
2. 応答が `ok: true` なら、それが状態表示になる。
3. `ok: false` なら、同じ文言を `chat.postMessage` でスレッドへ投稿する (代替の「一時メッセージ」)。

どちらの経路も `tests/Slack/SlackEgressTest.php` がフェイク API クライアントで駆動している。**実ワークスペースでどちらになったかは 7 章で人が見る** — 状態が本文とは別の場所に出れば 1、`Working on it.` というメッセージがスレッドに増えれば 3 である。

> 代替が選ばれた場合、そのメッセージは消さない。`chat.delete` を足すのは簡単だが、消し損ねたときに残るのは「消えるはずだったもの」であり、`chat:write` 以上の権限も要る。#15 でストリーミングに移ればこの分岐ごと無くなる。

## 4. ツール開始 / 完了の見え方と、結線

ツールの開始・完了は**パイプラインが引用行に包んでから** `StreamHandle` へ流す (`CompletedTurn::TOOL_NOTICE`)。Slack はこれを引用として描画するので、本文と一目で区別が付く。

| 出るもの | 形 |
|---|---|
| ツール開始 | `> Grep` |
| ツール完了 | `> toolu_1 done` / `> toolu_1 failed` |
| 応答本文 | そのまま |

完了行が**ツール名ではなく呼び出し id を名乗る**のは、`ToolCompleted` が `id` と `success` しか持たないため (`docs/cli-adapter.md` 4 章と同じ理由)。

```
bin/agent-bridge-slack
  └ Slack\SlackCommand              引数無しの確認 → boot → 終了コード だけを担う
      ├ Di\Boot                     → コンパイル済み injector (slack コンテキスト)
      └ Slack\SlackServer           ← injector から解決するのはこれ 1 つだけ
          ├ Slack\SocketModeClient  別コルーチンで接続・ack・再接続 (#13)
          │   └ Slack\FrameRouter   → Coroutine\Channel へ payload を push
          ├ Slack\SlackIngress      (ChatIngress) Channel を読み IncomingMessage を列挙
          │   └ Slack\ThreadChannels  スレッド → channel を書き留める
          └ BecomingInterface       メッセージごとに go() で 1 回呼ぶだけ
              └ Slack\SlackEgress   (ChatEgress) ← SlackModule が束縛
                  ├ Slack\SlackReply    (StreamHandle) 差分をため、close で 1 通投稿
                  └ Slack\SlackApiClient    Web API はこのインターフェース越しだけ
                      └ Slack\SwooleSlackApiClient  Swoole\Coroutine\Http\Client を使う
```

**スレッドごとのディスパッチャは無い。** 1 スレッドが 1 度に 1 ターンしか走らせないことは実行層 (`Runner\TurnLocks`) が既に保証しており、それが worktree の直列化でもある。別スレッドは同時に走る。

`ThreadChannels` は「そのスレッドがどの channel のものか」をプロセス内に覚えているだけの写像で、永続化しない。ThreadId には channel を入れない (ポートの宣言に Slack 固有の語彙を出さないという #10 の制約) ためで、再起動後は次のメッセージが来た時点で埋め直される。session_id と worktree は導出なので、この写像が空でも文脈は切れない。

### 引数と環境変数

```
usage: agent-bridge-slack [APP_DIR]
```

| | 意味 |
|---|---|
| `APP_DIR` (引数、省略可) | コンパイル済み DI スクリプトを読むディレクトリ。既定は `bin/` の親 |

**パスは引数で、秘密は環境変数で渡す。** 引数が 2 つ以上あれば usage を書いて exit 2。

| 変数 | 必須 | 意味 |
|---|---|---|
| `SLACK_APP_TOKEN` | ○ | `xapp-` で始まる app-level token。Socket Mode 接続を開く (`connections:write`) |
| `SLACK_BOT_TOKEN` | ○ | `xoxb-` で始まる bot token。Web API 呼び出しに使う |
| `SLACK_BOT_USER_ID` | ○ | 自 app の bot user ID (`U…`)。**自分の投稿を無視するために要る** |
| `AGENT_BRIDGE_REPOSITORY` | | worktree を切り出す元のリポジトリ (既定: カレントディレクトリ) |

3 つのトークンは起動時に読む。欠けていれば**接続する前に**理由を書いて exit 3 で落ちる。

---

## 5. Slack アプリの設定

`docs/slack-socket-mode.md` の 1〜3 章 (Socket Mode の有効化と app-level token) を先に済ませてあることを前提にする。そのうえで、この アダプタが動くのに要るのは次の 3 つ。

**Event Subscriptions → Subscribe to bot events**

| イベント | 何のため |
|---|---|
| `app_mention` | チャンネルでのメンション |
| `message.channels` | メンションで始まったスレッドへの、メンション無しの返信 |
| `message.im` | ダイレクトメッセージ |

**OAuth & Permissions → Bot Token Scopes**

| スコープ | 何のため |
|---|---|
| `app_mentions:read` | 上のイベントを受け取る |
| `channels:history` | スレッド返信を受け取る |
| `im:history` | ダイレクトメッセージを受け取る |
| `chat:write` | `chat.postMessage` で答える。`assistant.threads.setStatus` もこれで足りる |

**Bot User ID を控える。** Basic Information / App Home に出ている `U` で始まる ID で、`SLACK_BOT_USER_ID` に入れる値。

スコープを足したら **Install to Workspace をやり直す** (足しただけでは反映されない)。テスト用チャンネルにアプリを招待しておく (`/invite @<アプリ名>`)。

## 6. 起動する

```bash
composer install
composer compile                       # serve と slack の両方を書く

export SLACK_APP_TOKEN='xapp-…'
export SLACK_BOT_TOKEN='xoxb-…'
export SLACK_BOT_USER_ID='U…'
export AGENT_BRIDGE_REPOSITORY="$PWD"  # worktree を切る元

php bin/agent-bridge-slack                 # APP_DIR 省略 = リポジトリ直下
# php bin/agent-bridge-slack /srv/agent-bridge   # 別の場所に compile してある場合
```

確認すること:

- 数秒以内に `[slack] connected` が出る。
- 3 つの環境変数のどれかを消して起動すると、**接続を試みる前に**その変数名を含むメッセージで落ちる。
- コンパイル済みスクリプトの無いディレクトリを `APP_DIR` に渡すと、そのパスを含むメッセージで exit 3 になる。
- ログにトークンが出ない。

以降の章は、このプロセスを動かしたまま行う。

## 7. メンションに応答が同じスレッドで返る

1. 招待したチャンネルで `@<アプリ名> このリポジトリは何をするもの?` と発言する。
2. **応答が「チャンネル直下」ではなく、その発言のスレッドに返る**こと。
3. 状態表示 (3 章) がどちらの形で出たかを記録する — スレッド上部などの状態行なら `setStatus` が効いており、`Working on it.` というメッセージが増えていれば代替に落ちている。**どちらでも正しい。**
4. ツールを使う質問 (`このリポジトリの composer.json を読んで` など) をすると、応答の中に `> Read` のような引用行と、`> toolu_… done` の完了行が混ざること。
5. `ls .worktrees/` に `slack-<ts>` の形の worktree が 1 つできていること。

## 8. 同一スレッド 5 往復で文脈が保たれる

1. 7 章のスレッドで、続けて 5 回やり取りする。3 回目以降に「さっき何を聞いた?」「1 つ前の答えをもう一度」のような、前を参照しないと答えられない質問を混ぜる。
2. 5 回とも**同じスレッドに**返ること。
3. **前の文脈を踏まえた答えが返る**こと (`--resume` で同じセッションに続いている証拠)。
4. `ls .worktrees/` が増えていないこと — 5 往復とも同じ worktree である。

## 9. 別スレッド 2 本を同時に走らせて混線しない

1. チャンネルで**別々の**メッセージとして 2 回メンションし、スレッドを 2 本作る (A と B)。
2. A に「あなたの合言葉は ALPHA。覚えて」、B に「あなたの合言葉は BRAVO。覚えて」と、**続けて素早く**送る。
3. A に「合言葉は?」と聞くと `ALPHA`、B に聞くと `BRAVO` が返ること。
4. 応答が**それぞれのスレッドにだけ**返り、片方の答えがもう片方に出ないこと。
5. `ls .worktrees/` に worktree が 2 つあること。

## 10. 別スレッド 2 本が別 worktree で並行編集して衝突しない

1. 9 章の 2 スレッドに、**同じファイル名**への書き込みを同時に頼む。
   - A: `README.md の先頭に「ALPHA」という行を足して`
   - B: `README.md の先頭に「BRAVO」という行を足して`
2. 両方が成功すること (どちらかが「ファイルが変わっている」と言わない)。
3. それぞれの worktree で内容が独立していること:

   ```bash
   head -1 .worktrees/slack-<A の ts>/README.md   # ALPHA
   head -1 .worktrees/slack-<B の ts>/README.md   # BRAVO
   ```

4. **元のリポジトリの作業ツリーが汚れていない**こと (`git status` が clean)。

## 11. サーバ再起動後も同じ worktree に着き文脈が継続する

1. 8 章のスレッドを使う。
2. `php bin/agent-bridge-slack` を `Ctrl-C` で止め、もう一度起動する。
3. 同じスレッドで「さっき何の話をしていた?」と聞く。
4. **前の文脈を踏まえた答えが返る**こと (session_id は ThreadId からの導出なので、プロセスをまたいでも同じ)。
5. `ls .worktrees/` が増えていないこと — 再起動前と同じ worktree に着いている。
6. `ThreadChannels` はプロセス内の写像なので再起動で空になるが、**手順 3 のメッセージが来た時点で埋まる**ため返信は届く。ここで届かなければ写像の埋め直しが壊れている。

## 12. 長時間タスク (5 分以上)

1. 時間のかかる依頼を出す (`src/ 以下のすべてのクラスについて 1 行の要約を作って` など)。
2. 受付直後に状態表示が出ること。
3. **5 分以上かかっても切れない**こと — Socket Mode の接続が無音タイムアウトで落ちない、ターンタイムアウト (`LifecycleSettings`) に当たらない。
4. 終わったときに、答えが**1 通のメッセージ**としてスレッドに投稿されること (この段は `chat.postMessage` なので、途中経過は出ない)。
5. その間に**別のスレッド**へメンションすると、そちらは待たされずに答えること (別スレッドは同時に走る)。

## 13. 同じ ThreadId を CLI と Slack の両方から使う

これがフロントエンド抽象の要になる確認である。

1. 7 章のスレッドの ThreadId を控える。`slack:<ts>` の `<ts>` は worktree の名前 (`.worktrees/slack-1700000001-123456`) から読める — `-` を `.` に戻したものが `ts`。
2. Slack のそのスレッドで「あなたの合言葉は CHARLIE。覚えて」と送る。
3. 端末から**同じ ThreadId** で CLI アダプタを叩く:

   ```bash
   echo '合言葉は?' | php bin/agent-bridge-cli slack:1700000001.123456
   ```

4. **`CHARLIE` が返る**こと。同じ session_id に着いている。
5. `ls .worktrees/` が増えていないこと。同じ worktree に着いている。
6. 逆向きも見る: CLI で「合言葉を DELTA に変えて」と送ってから、Slack の同じスレッドで「合言葉は?」と聞くと `DELTA` が返ること。

---

## 付録: 自動テストが覆っている範囲

| どこ | 何を見ているか |
|---|---|
| `tests/Slack/SlackMessageTest.php` | ThreadId の規則と、無視するものすべて (2 章の表) |
| `tests/Slack/SlackIngressTest.php` | Channel → `IncomingMessage` の列挙と、channel の書き留め |
| `tests/Slack/SlackEgressTest.php` | 状態表示の 2 経路、差分のバッファ、`thread_ts` の付与、ツール行の区別。パイプライン経由と直接の両方 |
| `tests/Slack/SlackApiResponseTest.php` | 応答 body の解釈 (**Slack は断るときも HTTP 200 で `ok: false` を返す**) |
| `tests/Slack/SlackApiClientTest.php` | Web API がインターフェース越しであること、本番実装が `Swoole\Coroutine\Http\Client` を使うこと、bot token の検証 |
| `tests/Slack/SlackWiringTest.php` | slack コンテキストが何に繋がっているか、ingress と egress が同じ写像を見ていること、ポートより下が Slack を知らないこと |
| `tests/Slack/SlackAdapterDocTest.php` | この手順書の各章が残っていること |

**Slack トークンを要求するテストは 1 つも無い。** Web API はフェイクを差し、Socket Mode は #13 のフェイク接続を使う。実接続で初めて分かること — スコープが足りているか、`setStatus` が通常スレッドで通るか、5 分の沈黙で切れないか — が、7 章以降に寄せてあるものである。
