# agent-bridge — PoC 設計書

**ステータス:** Draft / PoC
**スコープ:** 個人利用。チャットから Claude Code をスレッド単位で継続実行する
**最終更新:** 2026-08-10 (実測にもとづく訂正を反映)

---

## 0. 訂正記録

初版の設計書には、実測で誤りと判明した記述が 3 箇所あった。本文は訂正済みだが、判断の経緯を残すためここに列挙する。実測ログは `.task-prep/verification-2026-08-10.md` (未追跡) を参照。

| # | 初版の記述 | 実測結果 | 訂正 |
|---|---|---|---|
| 1 | 4.3 案 A: `--session-id UUID` を再指定すると同じセッションに追記される | **`Error: Session ID ... is already in use.` / exit 1。** `--resume` なら継続する | 案 A′ へ (4.3)。**永続ストアレスは維持** |
| 2 | 4.1: `ContextProvider::get($context, $meta)` / `new AppMeta($appDir)` | `AppMeta` は 4 引数 (絶対パス必須)、`ContextProviderInterface::get(AppMeta)` はインスタンスメソッド | 4.1 を実 API に合わせて全面書き換え |
| 3 | 4.5: 必要スコープ `assistant:write` | ストリーミング 3 メソッドは **`chat:write`** | 4.5 を訂正。スロットル既定値も変更 |
| 4 | 8 章: 「5 までは素の `new` で書いて構わない。DI は後回し」 | **Be Framework を最初から使う方針にしたため成立しない。** `Becoming` は `#[Inject]` を Ray.Di で解決する | DI (`ServeContext`) を Be パイプラインより前に置く。実装順序を 8 章で書き換え |

---

## 1. 目的

チャットのスレッドを 1 つの Claude Code セッションに対応させ、スマホからでも会話を継続できるようにする。

フロントエンド (Slack / CLI / Discord ...) と実行層 (CLI / API) の双方をアダプタで抽象化し、その間を橋渡しするのがこのリポジトリの役割。名前もそこから採っている。

**PoC のゴール**

1. スレッド内で文脈が維持される
2. 実行中の進捗が Slack 上でリアルタイムに見える
3. 常駐 PHP プロセス上で BEAR.Sunday のリソース層が問題なく動く
4. フロントエンドと実行層の抽象が、実際に 2 種類の実装で成立する
5. **常駐 PHP プロセス + コンパイル済み Injector の上で Be Framework の `Becoming` が動く**

ゴール 4 の内訳: フロントエンドは CLI アダプタと Slack アダプタ、実行層は `PersistentCliRunner` と `SpawnCliRunner`。

**非ゴール (PoC 段階では作らない)**

- 権限リクエストの往復 UI (`--allowedTools` の事前指定で代替)
- 実行中の割り込み (常駐方式なので後から足せるが、PoC ではターンを直列化する)
- マルチユーザー / マルチワークスペース対応
- Discord / Telegram アダプタの実装 (ポートは切るが、PoC では CLI と Slack のみ)
- 複数リポジトリの動的切り替え

---

## 2. 前提と制約

| 項目 | 決定 |
|---|---|
| 認証 | ローカルの Claude Code のログイン情報をそのまま利用 |
| 実行主体 | 個人利用のみ |
| フロントエンド | アダプタで抽象化。PoC では CLI → Slack の順に実装 |
| Slack 接続 | **Socket Mode** |
| 作業ディレクトリ | 単一リポジトリ。スレッドごとに git worktree で分離 |
| 永続化 | **なし**。状態は Claude Code のセッションと git の worktree に置く |

検証時の環境: PHP 8.5.5 / Swoole 6.2.0 / Composer 2.9.7 / Claude Code 2.1.223 / macOS (Apple Silicon)。

### Socket Mode を選ぶ理由

PHP-FPM 前提なら公開エンドポイントが必要になり HTTP Events API 一択だが、Swoole は常駐プロセスとイベントループを持つため WebSocket を維持できる。インバウンドの口を開けずに済むのが、個人利用では大きい。

---

## 3. アーキテクチャ

```
Swoole Server (常駐 1 プロセス)
  [起動時] Injector 構築 → warmup → 使い回し

  Coroutine A: Socket Mode Client
    apps.connections.open → WSS 接続
    envelope 受信 → 即 ack → Channel push
    disconnect フレーム → 再接続

           │ Coroutine\Channel
           ▼
  Coroutine B: Dispatcher
    ThreadId ごとの Channel(容量1) で直列化
    → ハンドラを直接 invoke

           ▼
  ThreadMessageHandler
    ├─ ThreadId から session_id / worktree を導出
    ├─ AgentRunner で送受信
    ├─ ChatEgress で Slack へ
    └─ 必要に応じて ResourceInterface

           ▼
  claude (子プロセス。ThreadId ごとに 1 つ、常駐)
```

**初版からの変更:** `Swoole\Table` による `thread_ts → session_id` の対応表は持たない。session_id は ThreadId から導出できるため (4.3)。

---

## 4. コンポーネント

### 4.1 起動 / DI

**入り口はリソースではない。** Slack の envelope を HTTP リクエストに見立てる必要はなく、内部で `ResourceInterface` を使いたいだけ。したがって `bear/skeleton` ベースにはせず、必要なものだけを組む。

依存: `bear/resource` / `ray/di` / `naoki-tsuchiya/ray-di-context`

#### ray-di-context 0.2.0 の実 API (実測済み)

```php
// AppMeta は 4 引数。appDir は絶対パス必須
// 既定パスで作るなら AppMeta::fromAppDir()
//   compileDir 既定 = APP_DIR/var/di/CONTEXT
//   tmpDir     既定 = APP_DIR/var/tmp/CONTEXT
$meta = AppMeta::fromAppDir($appDir, $context);

// ContextProviderInterface::get はインスタンスメソッド
// 標準実装は MapContextProvider (コンテキスト名 → クラスの写像)
$provider = new MapContextProvider(['serve' => ServeContext::class]);
$context  = $provider->get($meta);

// ContextInterface: __invoke(): AbstractModule
//                   getInjectorInstance(): InjectorInterface
//                   getSavedSingleton(): array
$injector = $context->getInjectorInstance();   // ★ 起動時 1 回だけ呼ぶ
```

**重要な契約:** `getInjectorInstance()` は繰り返し呼んで同じインスタンスが返る保証が無い。`AbstractCompiledContext` は実際に**呼ぶたびに `new CompiledInjector` を返す**。1 回だけ呼んで使い回すのは呼び出し側の責務。

その他:

- コンテキストクラスは `AbstractContext` を継承 (コンストラクタは `final`、`AppMeta` のみを受ける)。コンパイル済み Injector が欲しければ `AbstractCompiledContext` を継承して `appModule()` を実装する
- **`compileDir` は作られるが `tmpDir` は作られない。** 事前に mkdir が要る。無いと Ray.Di が黙って `sys_get_temp_dir()` へフォールバックする
- `var/di/` と `var/tmp/` を `.gitignore` に入れる
- コンパイル用 CLI `bin/ray-di-compile` が同梱
- `BakedPathGuard` で、`toInstance()` による秘密やパスの焼き込みを検出できる。**秘密はプロバイダ経由で束縛する**

> **skeleton を使わない理由:** `public/index.php`、ルーティング、`prod-html-app` のような HTTP 前提のコンテキストが全て不要なため。
>
> **BEAR.Runtime の narrow waist はこの PoC では使わない。** 入り口が HTTP でない以上、envelope を擬似 `$server` に変換する層は無駄な間接層になる。

### 4.2 Socket Mode クライアント

```
1. apps.connections.open (App-level token: xapp-) → WSS URL
2. Swoole\Coroutine\Http\Client で upgrade
3. recv ループ
   - type=hello        → ログのみ
   - type=events_api   → envelope_id で即 ack、payload を Channel へ
   - type=disconnect   → 再接続 (指数バックオフ)
4. 一定時間 recv なし → 接続破棄して再接続
```

**注意点**

- ack は 3 秒以内。処理は必ず Channel 経由で後段へ流し、ここでは絶対にブロックしない
- Slack は定期的に切断してくるため、再接続は正常系として実装する
- `envelope_id` の重複配信がありうるので、直近 N 件を覚えて冪等にする
- **`Swoole\Table` は使わない。** プロセス間共有が目的の機構だが、単一プロセス構成では共有する相手がいない。素の連想配列 + 件数上限で足りる
- **接続確立はインターフェースの裏に置く。** そうしないと再接続とタイムアウトを実接続なしに検証できない

### 4.3 セッションの同定

**会話履歴の実体は持たない。** Claude Code が `~/.claude/projects/<cwd ハッシュ>/<uuid>.jsonl` に保持している。必要なのはスレッドとセッションを対応づける手段だけ。

#### 案 A′: 決定的導出 + コマンド分岐 (採用)

```
session_id = UUIDv5(名前空間 UUID, ThreadId)
名前空間 UUID = 33adc75c-ded9-51f3-b48f-fe0eebd1fcbf
```

**実測で判明した `--session-id` の実際の意味:**

| 状況 | 挙動 |
|---|---|
| 新規 UUID で `--session-id` | 成功。`.jsonl` のファイル名が指定 UUID と一致 |
| 既存 UUID で `--session-id` | `Error: Session ID ... is already in use.` / exit 1 |
| 既存 UUID で `--resume` | 成功。文脈を保持 |
| 存在しない UUID で `--resume` | `No conversation found with session ID: ...` / exit 1 |
| 常駐モードで存在しない UUID を `--resume` | stdin に書く前に約 1.6s で終了。exit 1。stdout に `result` (`subtype: error_during_execution`, `num_turns: 0`) が 1 行 |
| cwd が違えば同じ UUID でも別セッション | 文脈は共有されない |

つまり `--session-id` は**新規作成専用**。初版の案 A は成立しない。

**採用する手順:**

1. `--resume SESSION_ID` で起動する
2. **最初のイベント行を受信する前にプロセスが終了したら**、`--session-id SESSION_ID` で起動し直し、保留中のプロンプトを送る

これで得られるもの:

- 永続ストアが不要 (`Swoole\Table` も JSONL も SQLite も要らない)
- 保持期間切れで消えたセッションも自動的に新規開始へフォールバックする
- `~/.claude/projects/` の内部ディレクトリ命名規則に依存しない
- プロセス再起動でスレッドの継続性が失われない

#### 4.3.1 ワークツリーの紐付け

異なるスレッドを並行実行すると、同じ作業ディレクトリで複数の `claude` がファイルを編集して衝突する。したがってスレッドごとに worktree を分ける。

worktree を分けると cwd が変わる。セッションはプロジェクトの絶対パスでキーされるため、**同じスレッドは常に同じ worktree に着かなければならない**。

**ブランチ元をデフォルトブランチに固定すれば、これも全て導出できる。**

```
slug     = ThreadId 中の ':' '.' '/' を '-' に置換
cwd      = .worktrees/<slug>
branch   = agent/<slug>
base     = デフォルトブランチ (固定)
session  = UUIDv5(名前空間, ThreadId)
```

デフォルトブランチの解決規則: `git symbolic-ref refs/remotes/origin/HEAD` が解決できればそれを、できなければ `git symbolic-ref HEAD` を使う。ハードコードしない。

初回判定と復旧:

| 状況 | 挙動 |
|---|---|
| ディレクトリがある | そのまま使う |
| ディレクトリも git 上の登録も無い | `git worktree add` で新規作成 |
| ディレクトリは無いが git 上に登録やブランチが残っている | `git worktree prune` の後、既存ブランチを再チェックアウトして**復旧する** |

例外にするのは復旧不能な失敗のみ (パスが repo 外を指す、`git worktree add` 自体の失敗)。

- **永続ストアは不要。** ディスク上の worktree の存在自体が状態になる
- ワーカー数にも構成にも依存しない
- 掃除は worktree ディレクトリの最終更新時刻で判断できる

> **この PoC は永続ストアを一切持たない。** 状態は全て Claude Code 側 (`~/.claude/projects/`) と git (`.worktrees/`) にある。

#### 4.3.2 その他の共通制約

- 保持期間を過ぎたセッションは消える。案 A′ では `--resume` の失敗を検知して新規開始へ自動フォールバックする
- worktree だけ残ってセッションが消えた場合、作業ツリーの状態は残るが文脈は失われる。この非対称は許容し、新規セッションとして続行する

### 4.4 実行層

#### 4.4.1 インターフェース

```php
interface AgentRunner
{
    /** @return iterable<AgentEvent> */
    public function send(ThreadId $thread, string $prompt): iterable;
    public function close(ThreadId $thread): void;
}
```

| 実装 | 位置づけ |
|---|---|
| `PersistentCliRunner` | **PoC の本命。** プロセスを保持して stdin に流す |
| `SpawnCliRunner` | 都度 `claude -p`。**ゴール 4 の 2 実装目**であり、常駐が不安定だった場合のフォールバックでもある |
| `ApiRunner` | Agent SDK / API 経由。PoC では作らない |

**抽象化の難所は出力イベントの正規化。** CLI の `stream-json` と API のイベント形式は異なるので、`AgentEvent` (テキスト差分 / ツール開始 / ツール完了 / 完了 / エラー) という共通型に落とす。

セッションの同定方法も実装ごとに違う。**この差はインターフェースに漏らさない**。`ThreadId` だけを受け取り、内部でどう解決するかは実装の責務とする。

**cwd の解決経路:** Runner は `ThreadId → cwd` を解決するコラボレータをコンストラクタで受け取る。`send()` のシグネチャに cwd を足さない (実行層の差異がインターフェースに漏れるため)。

#### 4.4.2 PersistentCliRunner

```
claude -p
  --input-format stream-json
  --output-format stream-json
  --verbose
  --include-partial-messages        # ★ これが無いと差分が流れない
  --resume <derived> | --session-id <derived>
  --allowedTools <許可リスト>
```

- cwd はそのスレッドに割り当てられた worktree (4.3.1)
- スレッドごとに 1 プロセス。`Swoole\Process` (coroutine 有効) で起動し、stdin/stdout を保持する
- 送信は stdin に JSONL を 1 行。`{"type":"user","message":{"role":"user","content":[{"type":"text","text":"..."}]}}`
- 受信は stdout を行単位で読み、`AgentEvent` に変換して yield する
- **ターン境界は `{"type":"result",...}`**。result 後もプロセスは生存する
- **stdin を閉じると exit 0 で正常終了する**

**stream-json の実測 (Claude Code 2.1.223)**

観測されたイベント種別:

```
system/hook_started, system/hook_response, system/init, system/status,
system/post_turn_summary, assistant, rate_limit_event, result,
stream_event/{message_start, content_block_start, content_block_delta,
              content_block_stop, message_delta, message_stop}
```

- `system/init` は**ターンごとに再送される**。セッション開始の合図に使わない
- `content_block_delta` の `delta.type` は `text_delta` / `thinking_delta` / `signature_delta`。**本文差分は `text_delta` のみ**
- ツール呼び出しは `assistant` の `message.content[]` に `type: "tool_use"` (`name`, `id`)

**プロセスはキャッシュであって状態の真実ではない**

Claude Code が `.jsonl` に書き続けているため、プロセスを殺しても履歴は残る。同じ UUID で `--resume` すれば続きから再開できる。したがって:

- アイドルタイムアウトで自由に殺してよい (15 分程度から始める)
- 同時プロセス数に上限を設け、超えたら LRU で殺す
- プロセスが死んでいたら次の送信時に黙って起動し直す。エラーにしない

`ThreadId → プロセスハンドル` のマップはメモリ上で持つ。**永続化しない。**

**ハマりどころ**

- stdin を閉じ忘れるとプロセスが終了しない。`close()` で明示的に閉じる
- **`close()` で blocking な `Swoole\Process::wait(true)` を使わない。** `Coroutine\run()` 内で他のコルーチンが全部止まる
- パイプのバッファリングでストリーミングが詰まる。行の組み立ては自前で行う
- ゾンビ化。回収は kill 後に必ず wait で刈り取る。SIGCHLD ハンドラと明示 wait の併用は二重回収で衝突するので避ける
- transcript の `.jsonl` は内部形式のため直接読まない
- 一発実行では stdin を閉じないと `Warning: no stdin data received in 3s` が出る

#### 4.4.3 ターンとタイムアウトの制御

**PoC では割り込みを許可しない。** stdout の完了イベントを待ってから次を送る。`ThreadId` ごとに `Swoole\Coroutine\Channel` (容量 1) を mutex として持ち、直列化する。

異なるスレッド同士は並行して構わない — worktree が分かれているため。`ThreadId` と worktree が 1:1 なので、スレッドの直列化がそのまま worktree の排他になる。

**2 種類のタイムアウトを分けて定義する。** 「無応答のプロセス」は「アイドル」ではなく「ターン実行中」である。

| 名前 | 条件 | 挙動 |
|---|---|---|
| アイドルタイムアウト | ターン未実行かつ最終利用から T_idle 経過 | 回収する。次の送信で黙って起動し直す |
| ターンタイムアウト | 送信後 T_turn 経過しても完了イベントが来ない | kill し、`send()` をエラーイベントで終端する |

LRU 回収では**実行中のターンを抱えているプロセスを対象から外す**。全プロセスが実行中で上限超過なら、いずれかの完了まで待つ。

### 4.5 Slack 出力層

| フェーズ | 使う API |
|---|---|
| 受付直後 | `assistant.threads.setStatus` |
| ツール実行中 | `chat.appendStream` に task update チャンク |
| 本文生成中 | `chat.appendStream` に markdown チャンク |
| 完了 | `chat.stopStream` |

#### 実測した仕様 (docs.slack.dev、2026-08 時点)

| メソッド | 必要スコープ (bot token) | レート制限 |
|---|---|---|
| `chat.startStream` | **`chat:write`** | Tier 2 (20+/分) |
| `chat.appendStream` | **`chat:write`** | Tier 4 (100+/分) |
| `chat.stopStream` | **`chat:write`** | — |
| `assistant.threads.setStatus` | `assistant:write` または `chat:write` (今後 `chat:write` のみになると公式が告知) | — |

- `markdown_text` は **12,000 文字**上限、`task_update` / `plan_update` チャンクは **256 文字**上限
- ストリーミング 3 メソッドに「Agents and AI Apps の有効化が必須」という記載は公式には無い
- `assistant.threads.setStatus` は assistant スレッド向け。**通常チャンネルのスレッドで機能する保証は読み取れない**ので、代替 (一時メッセージ等) を用意する

**実装上の作法**

- ストリーミングはスレッド返信のみ。`thread_ts` は必須
- **スロットルの既定は 600ms 以上。** 初版の「300ms 程度」は 200 回/分に相当し、`chat.appendStream` の Tier 4 を超過する
- デルタのみ送る (全文を毎回送らない)
- 最初のテキストチャンクで `chat.startStream`、以降 append、終端で stop
- HTTP 429 と `Retry-After` を受けたら待って再送し、ストリームを落とさない
- ストリーム開始に失敗したら `chat.postMessage` へフォールバックする

### 4.6 フロントエンド抽象

#### 4.6.1 ポート

```php
interface ChatIngress
{
    /** @return iterable<IncomingMessage> */
    public function listen(): iterable;
}

interface ChatEgress
{
    public function open(ThreadId $thread): StreamHandle;
    public function status(ThreadId $thread, string $text): void;
}

interface StreamHandle
{
    public function append(string $delta): void;
    public function close(): void;
}
```

**ポートが約束するのは「差分を append する」ことだけ。** スロットリングも実現方法もアダプタの責務にする。

| | 実現方法 | 目安 |
|---|---|---|
| Slack | `chat.startStream` / `appendStream` / `stopStream` | **600ms 以上** (Tier 4) |
| Discord | メッセージ編集の連打 | レスポンスヘッダを見て動的に調整 |
| Telegram | `sendMessageDraft` | 1 秒程度 |
| CLI | 標準出力にそのまま | スロットル不要 |

ここをポート側で固定すると、どれかのアダプタが必ず破綻する。

**ポートの宣言に Slack 固有の語彙 (`thread_ts` / `channel` / `slack`) を出さない。**

#### 4.6.2 ThreadId の名前空間

```
ThreadId = "<platform>:<native-id>"
  slack:1700000001.123456
  discord:1234567890123456789
  telegram:-1001234567890/42
  cli:my-experiment
```

- 分割は**最初の `:`** で行う。NATIVE_ID に 2 個目以降の `:` を含んでよい
- `/` と `..` は弾く (導出結果がディレクトリ名とブランチ名になるため)

既知解のテストベクタ (名前空間 `33adc75c-ded9-51f3-b48f-fe0eebd1fcbf`):

| ThreadId | session_id | slug |
|---|---|---|
| `cli:my-experiment` | `b0f400e4-b88d-5d39-a7ee-6cd49fbc4b39` | `cli-my-experiment` |
| `slack:1700000001.123456` | `959a94a6-5395-5d07-bc71-0a0c7d800476` | `slack-1700000001-123456` |
| `discord:1234567890123456789` | `69f77640-5a3a-5c50-b568-e888871d9b10` | `discord-1234567890123456789` |

Slack の `thread_ts` が無いメッセージは、そのメッセージの `ts` を使う。

#### 4.6.3 アダプタ候補

| | スレッド概念 | 接続 | 備考 |
|---|---|---|---|
| **CLI** | 自分で定義 | なし | **最初に作る** |
| **Slack** | `thread_ts` | Socket Mode | 仕事用。ネイティブのストリーミング API あり |
| **Discord** | スレッドが一級市民 | Gateway | アダプタが最も薄く済む |
| **Telegram** | 弱い。フォーラムトピックで代用 | ロングポーリング | モバイル体験は最良 |

いずれもアウトバウンド接続のみで成立する。

> **CLI アダプタを Slack より先に作る。** Socket Mode を書く前にコアが動くので、切り分けが楽になる。抽象化が正しいかの検証も兼ねる。

### 4.7 Be Framework による処理パイプライン

メッセージ処理は「becoming」の連鎖として表現する。**この PoC は「永続ストアを一切持たない」という設計の全体重を、ThreadId からの導出が常に正しいことに預けている** (4.3)。「まだ検証されていない ThreadId」「まだ存在しない worktree」を**型として表現できなくする**のが狙い。

| 段階 | 種別 | 存在が証明すること |
|---|---|---|
| `IncomingMessage` | Input | プラットフォームと本文を持つ生の入力 |
| `ResolvedThread` | Being | **ThreadId が検証済み・session_id が導出済み・worktree が実在する** |
| `CompletedTurn` | Final | ターンが完走し、イベントが流れきった |

#### 実 API (be-framework/be、2026-08-10 に vendor で確認)

- パッケージ名は **`be-framework/be`**。**タグ付きリリースが無く `0.x-dev` のみ**
- `require`: `php ^8.3` / `ray/di ^2.18` / `ray/input-query ^1.0` / `koriym/semantic-logger ^0.8`
- `BecomingInterface` は `__invoke(object $input): object` の 1 メソッド
- **`Becoming::__construct(InjectorInterface $injector, string $semanticNamespace = 'Be\App\Semantic', ...)`** — Ray.Di の `InjectorInterface` を直接受けるので、`CompiledInjector` をそのまま渡せる
- `Module\BeModule` のコンストラクタは `(string $namespace, AbstractModule|null $module)` で、親モジュールを合成できる
- `#[Be(...)]` は `Be\Framework\Attribute\Be` (`IS_REPEATABLE`、`string|array $being`)。`#[Input]` は `Ray\InputQuery` 由来

#### 役割分担

**Be がパイプライン、`bear/resource` が照会。** メッセージ処理の変換連鎖は Be、状態の照会・表現は `ResourceInterface`。層を分けることでゴール 3 とゴール 5 を同時に検証できる。

#### 未検証のリスク

`Becoming` がコンパイル済み Injector と Swoole の常駐プロセス・コルーチン上で正しく動くかは未確認。`BecomingArguments` はリフレクションを使うため、コンパイル済み束縛との相性は検証対象そのもの。問題が出たらコンパイルを諦めて素の `Injector` に落とす判断を含めてよい (理由を記録すること)。

**`be-framework/be` に安定版が無いため `minimum-stability: dev` が要る。必ず `prefer-stable: true` を併記する** — 併記しないと他の全依存まで dev 版に落ちる。

### 4.8 テスト戦略

実行層の検証で確かめたいこと (文脈継続 / 復帰 / LRU / 再起動後の継続) は、プロセスを起動しないと取れない。しかし実 `claude` はログイン・ネットワーク・課金・レート制限を伴い、CI では回せない。

そこで **`claude` と同じワイヤプロトコルを話すフェイク CLI** を用意する。

| 群 | 定義 | CI |
|---|---|---|
| unit | ログイン済み Claude Code と外部トークンを必要としない。フェイク CLI の起動は含む | 回す |
| integration | 実 `claude` プロセスの起動を伴う | 回さない |

境目は「プロセスを起動するか」ではなく「**ログインした実 Claude Code を要求するか**」。

**契約テスト**で、フェイクが本物とずれていないことを検証する。同一のテスト本体を実行バイナリだけ差し替えて 2 回走らせ、フェイク側を unit 群、実 `claude` 側を integration 群に置く。表明は応答テキストの厳密一致を避け、終了コード・イベント種別の系列・キーワード含有で行う。

**フェイクは代用であって仕様の正ではない。** 実 CLI の挙動が変わったら契約テストの integration 側が落ちる。落ちたらフェイクを直す — 逆にフェイクに合わせて実装を歪めない。

---

## 5. 主要な設計判断

| # | 判断 | 却下した案 | 理由 |
|---|---|---|---|
| 1 | Socket Mode | HTTP Events API | Swoole の常駐で維持できる。公開エンドポイント不要 |
| 2 | `claude` CLI 直叩き | ACP アダプタ | 権限の往復が不要なため、ACP のプロセス管理コストに見合わない |
| 3 | CLI 直叩き | Agent SDK | PHP に SDK がない。認証経路も CLI が素直 |
| 3b | 常駐プロセス (`--input-format stream-json`) | メッセージごとに都度起動 | 起動コストが消える。割り込みが将来足せる |
| 3c | `AgentRunner` で抽象化 | CLI 直結 | 認証や配布形態が変われば API に移る必要がある |
| 4 | Injector を起動時 1 回 | リクエスト毎に構築 | 常駐前提。`getInjectorInstance()` は毎回 new を返す契約なので、呼ぶのは 1 回だけ |
| 5 | `bear/resource` + ray-di-context | `bear/skeleton` / `bear/package` | 入り口が HTTP でないため |
| 6 | narrow waist を使わない | envelope → 擬似 `$server` 変換 | 不要な間接層になる |
| 7 | ThreadId から session_id を決定的に導出 + コマンド分岐 | 対応表を永続化 | 実測で `--session-id` が新規専用と判明したが、`--resume` 先行で導出方式は維持できる |
| 8 | worktree も ThreadId から導出 (ブランチ元は固定) | 割り当てを永続化 | 永続ストアが一切不要になる |
| 9 | `ChatIngress` / `ChatEgress` でフロントも抽象化 | Slack 直結 | Discord / Telegram / CLI に載せ替えられる必要がある |
| 10 | `ThreadId` にプラットフォーム名を含める | ネイティブ ID をそのまま使う | セッションと worktree が衝突しない |
| 11 | スロットリングはアダプタの責務 | ポート側で統一 | プラットフォームごとに制限も手段も異なる |
| 12 | フェイク CLI + 契約テスト | 実 `claude` だけで検証 | CI で回せず、遅く不安定になる。フェイクなら回収・LRU・メモリまで CI で見られる |
| 13 | **Be Framework を最初から使う** | 後付けで導入 / 使わない | 導出の正しさに設計の全体重が乗っているため、不正な状態を型で表現不能にする価値が大きい。後付けだと結線の手戻りになる。代償として DI が前に出る (訂正記録 4) |
| 14 | Be がパイプライン、`bear/resource` が照会 | どちらかに寄せる | 層が分かれ、ゴール 3 とゴール 5 を同時に検証できる |

---

## 6. 検証項目

### 6.1 事前検証 (実施済み: 2026-08-10)

- [x] `claude --version` → 2.1.223
- [x] `claude -p --session-id UUID` で新規セッションが作られ、`.jsonl` のファイル名が指定 UUID と一致する
- [x] 同じ UUID で 2 回目 → **`already in use` で exit 1**。`--resume` なら継続する
- [x] 存在しない UUID の `--resume` → `No conversation found` で exit 1
- [x] 常駐モードで存在しない UUID の `--resume` → stdin 前に約 1.6s で終了、`result` 1 行
- [x] cwd を変えて同じ UUID → 別セッション扱い
- [x] `--input-format stream-json` で 1 プロセス 2 ターン、文脈が継続する
- [x] ターン境界は `type: "result"`
- [x] プロセスを跨いで同じ UUID を `--resume` → 文脈が継続、`session_id` は同一
- [x] `--include-partial-messages` が無いと `stream_event` が流れない

### 6.2 結合検証

各項目は対応する issue の受け入れ条件に落としてある。

- [ ] Socket Mode の再接続が想定通り動く
- [ ] 3 秒 ack を守れている (後段が詰まっていても ack が返る)
- [ ] 同一スレッドで 5 往復して文脈が保たれる
- [ ] プロセスを再起動しても、既存スレッドの文脈が継続する
- [ ] 別スレッド 2 本を同時に走らせて混線しない
- [ ] 別スレッド 2 本が別 worktree で並行編集しても衝突しない
- [ ] プロセスを再起動しても、既存スレッドが同じ worktree に着く
- [ ] 長時間タスク (5 分以上) でストリームが切れない
- [ ] アイドルタイムアウトでプロセスが回収され、次の送信で自動復帰する
- [ ] ターンタイムアウトで `send()` がエラー終端し、呼び出し側がブロックしない
- [ ] 同時プロセス数の上限に達したとき LRU で回収される
- [ ] 子プロセスがゾンビ化しない (defunct を含めて 0 件)
- [ ] 常駐プロセスでメモリが頭打ちになる
- [ ] warmup したシングルトンが、イベント間で状態を持ち越さない
- [ ] コンパイル済み Injector が Swoole 上で正しく解決される
- [ ] リソース層が実際に動く (ResourceObject を `ResourceInterface` 経由で呼べる)
- [ ] `BecomingInterface` がコンパイル済み Injector から解決できる
- [ ] `$becoming(new IncomingMessage(...))` の 1 回の呼び出しで `CompletedTurn` まで到達する
- [ ] `ResolvedThread` を worktree が存在しない状態で構築できない
- [ ] 不正な ThreadId を持つ `IncomingMessage` は `CompletedTurn` に到達しない
- [ ] CLI アダプタと Slack アダプタで、実行層より下が一切変わっていない
- [ ] `PersistentCliRunner` と `SpawnCliRunner` を差し替えても CLI アダプタのテストが通る
- [ ] 同じ `ThreadId` を CLI と Slack の両方から使うと、同じセッション・同じ worktree に着く

---

## 7. リスクと未解決事項

| リスク | 対応方針 |
|---|---|
| warmup したシングルトンがイベント間で状態を持ち越す | フェイク CLI が受け取った session_id / cwd / 入力を照合して検出する |
| ray-di-context が Slack 用途で足りない | Lambda 側と共通の抽象なので、不足が出れば両方に効く改善として扱う |
| 子プロセスのゾンビ化 | kill 後に必ず wait。SIGCHLD ハンドラとの併用は避ける |
| ストリーミング API の仕様変更 | 出力層をインターフェースで抽象化し、`chat.postMessage` フォールバックを常に用意 |
| `assistant.threads.setStatus` が通常スレッドで使えない | 代替 (一時メッセージ) を用意し、採用したほうを docs に記す |
| レート制限超過 | スロットル既定を 600ms 以上に。429 と `Retry-After` を尊重する |
| 認証経路の方針変更 | 実行層を差し替え可能にしておき、API キー / Bedrock へ移せる形にする |
| 常駐プロセスが不安定 / パイプが詰まる | `SpawnCliRunner` にフォールバック |
| 子プロセスのリーク | アイドルタイムアウトと同時数上限を最初から入れる |
| フェイク CLI が本物からずれる | 契約テストの integration 側で検出する。落ちたらフェイクを直す |
| **`Becoming` がコンパイル済み Injector / コルーチンで動かない** | 8 の受け入れ条件で早期に判明させる。駄目なら素の `Injector` に落とし、理由を記録する |
| **`be-framework/be` に安定版が無い** | `minimum-stability: dev` + **`prefer-stable: true`** で他の依存を守る。破壊的変更に追随できるよう、Be に触れるのは 9 のパイプラインに閉じる |

---

## 8. 実装順序

0. ~~事前検証~~ (完了)
1. プロジェクト基盤 / CI / テスト群規約 / 依存の追加
2. ThreadId と決定的導出
3. `AgentEvent` の型とパーサ
4. **フェイク Claude CLI と契約テスト**
5. WorktreeManager
6. `AgentRunner` + `PersistentCliRunner`
7. プロセスのライフサイクル管理
8. `ServeContext` + コンパイル済み Injector + `BeModule`
9. **ポート定義と Be パイプライン** (`IncomingMessage` → `ResolvedThread` → `CompletedTurn`)
10. **CLI アダプタで端から端まで通す**
11. `SpawnCliRunner` (ゴール 4 の実証)
12. Socket Mode クライアント
13. Slack アダプタ (`chat.postMessage` 版)
14. Slack 出力をストリーミング API に差し替え

> **10 を Slack より前に置くのが要点。** CLI アダプタでコアが端から端まで動いてから Socket Mode に入る。Slack を先に書くと、不具合が Slack 側か実行層か切り分けにくくなる。
>
> **4 を早い段階に置くのも同じ理由。** フェイク CLI が無いと、以降の検証がすべて実 Claude Code 依存になり CI から落ちる。
>
> **8 (DI) が前に出ているのは Be のため。** `Becoming` は `#[Inject]` を Ray.Di で解決するので、9 のパイプラインより先に Injector が要る (訂正記録 4)。副次的に、13 / 14 の「実行層より下を変えていない」diff 検証に束縛移行が割り込まなくなる。
>
> **3 (`AgentEvent` の型) だけは先に決める。** ここが曖昧なまま実装すると、CLI の出力形式が実装全体に染み出して差し替えられなくなる。

---

## 9. 付録: マルチプロセス化

PoC のスコープ外だが、現設計がこの方向に矛盾しないことを確認しておく。

Socket Mode は最大 10 本の同時接続を維持でき、複数接続がアクティブなときは各ペイロードがいずれか 1 本に送られる。重複配信ではなくロードバランスであり、グレースフルな再起動のための active-active 冗長構成としても使える。

「どの接続にどのイベントが来るか予測できない」ため、受信と実行を分離する。

```
[Ingress x N]  Socket Mode 接続 → 即 ack → キューへ
      ↓
[Queue]        Redis List など
      ↓
[Worker x M]   ThreadId → worktree を引いて実行
```

### 現設計がそのまま効く点

| 要素 | マルチプロセスでどうなるか |
|---|---|
| `session_id` の決定的導出 | 共有するものがないのでそのまま動く |
| worktree の決定的導出 | 同上。どのプロセスが受けても同じ worktree に着く |
| worktree による作業分離 | ディスク上の分離なのでプロセスをまたいでも同じ効果 |
| 常駐 Claude プロセス | ワーカーごとに持つ。同じスレッドが別ワーカーに着くと二重起動になるため排他が要る |

### 追加で必要になるもの

- **分散ロック** — スレッドの直列化がプロセスをまたぐ。`Coroutine\Channel` では届かないので Redis のロックに置き換える
- **`envelope_id` の重複排除を共有ストアへ** — 現状はプロセスローカル
- **キュー** — Redis List か Swoole の Task Worker
- **worktree の共有** — 全ワーカーが同じファイルシステムを見る必要がある

導出方式のおかげで、マルチプロセス化で持ち込む状態は「ロック」と「重複排除」だけになる。どちらも短命なので Redis で完結し、永続ストアは最後まで不要。
