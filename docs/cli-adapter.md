# CLI アダプタ

**ステータス:** 実装済み (issue #11)
**対象:** `bin/agent-bridge-cli` / `src/Cli/`

---

## 1. これは何か

`ChatIngress` / `ChatEgress` / `StreamHandle` (#10) の最初の実装で、**端末が 1 つのフロントエンド**になる。Slack より先に作ってあるのは `poc-design.md` の実装順 10 の要点そのもので、コアが端から端まで動くことをここで見てから Socket Mode に入る。

スレッドは端末側に無いので、**呼び出し側が ThreadId を名乗る**。同じ ThreadId は Slack から使っても同じ session_id・同じ worktree に着く (導出しかしていないため)。

## 2. 1 往復させる

コンパイル済みの DI スクリプトが要る。無ければ boot の時点で exit 3 になる。

```bash
composer compile
echo 'このリポジトリは何をするもの?' | php bin/agent-bridge-cli cli:my-experiment
```

- 引数は `PLATFORM:NATIVE_ID` の 1 つだけ。分割は**最初の `:`** で行う (`slack:C123:456` の NATIVE_ID は `C123:456`)
- **標準入力の 1 行が 1 メッセージ**。空行は読み飛ばす。入力が終わればプロセスも終わる
- 応答は標準出力、状態表示は標準エラー。`> ツール名` の行はツール開始の通知

対話的に続けたいときは、そのまま何行でも打てる。

```bash
php bin/agent-bridge-cli cli:my-experiment
おはよう
さっき何て言った?
^D
```

worktree はリポジトリ直下の `.worktrees/<slug>` に作られ、2 回目以降は同じものが使われる。

```bash
ls .worktrees/          # cli-my-experiment
```

## 3. 環境変数

| 変数 | 既定 | 意味 |
|---|---|---|
| `AGENT_BRIDGE_REPOSITORY` | カレントディレクトリ | worktree を切り出す元のリポジトリ (`BaseRepositoryProvider`) |
| `AGENT_BRIDGE_APP_DIR` | `bin/` の親 (= プロジェクトルート) | コンパイル済みスクリプトを探すディレクトリ |

`claude` バイナリは `PATH` から解決する (`ClaudeCliSettings`)。テストがフェイクを差すときも、実行層の設定ではなく `PATH` を差し替えている。

## 4. 出力の読み方

| 行 | 出力先 | 例 |
|---|---|---|
| 応答本文 | 標準出力 | 差分が届いたそばから書かれる。ターンの終わりに改行 1 つ |
| ツール開始 | 標準出力 | `> Grep` (パイプラインが `CompletedTurn::TOOL_NOTICE` で付ける引用行) |
| 状態表示 | 標準エラー | `# Working on it.` (受付直後に 1 回) |

応答と状態を分けてあるのは、**標準出力をそのままパイプできるようにするため**。`cmd > answer.txt` に状態表示が混ざらない。

ツール完了は何も出さない。`CompletedTurn` が意図的に黙っており (`ToolCompleted` の生産者もまだ無い)、`tests/Pipeline/BecomingChainTest.php` がその沈黙を固定している。

## 5. exit code

| code | いつ |
|---|---|
| 0 | 全ターンが完走した (答えるものが無かった場合も含む) |
| 1 | ターンが 1 つでも失敗した / worktree を切れなかった |
| 2 | コマンドラインが ThreadId を名乗っていない、または名乗った ThreadId が使えない |
| 3 | プロセスを立ち上げられない (コンパイル済みスクリプトが無い等) |

## 6. 結線

```
bin/agent-bridge-cli
  └ Cli\CliCommand
      ├ Di\Boot            → コンパイル済み injector (1 プロセス 1 回、warmup 込み)
      ├ BecomingInterface  → メッセージを 1 回渡すだけ。遷移は書かない
      ├ Cli\StandardInputIngress   (ChatIngress)
      └ Cli\StandardOutputEgress   (ChatEgress) ← AppModule が束縛、php://output と php://stderr
```

`ChatEgress` の束縛は `Di\AppModule` にある。フロントエンドが 1 つしか無いうちはこれが既定で、#14 で Slack が入るときに「選ぶ」ことになる。

turn を終えたあとに `AgentRunner::close()` を呼んでいるのは、プール監視のコルーチンが生きている間 `Swoole\Coroutine\run()` が戻らないため — 呼ばないとプロセスが終わらない。

## 7. テスト

| どこ | 何を見ているか |
|---|---|
| `tests/Cli/CliRoundTripTest.php` | `bin/agent-bridge-cli` を**実プロセスとして**起動する端から端まで (往復・worktree・プロセスを跨いだ文脈継続・並行・exit code) |
| `tests/Cli/CliChainOutputTest.php` | 状態表示と応答の順序、差分が複数回の書き込みで届くこと (`RecordingStream`) |
| `tests/Cli/WarmupIsolationTest.php` | 同一 injector・同一ハンドラで 2 スレッドを順に処理し、2 つ目の記録に 1 つ目の値が無いこと |
| `tests/Cli/ProductionWiringTest.php` | コンパイル済み injector が何に繋がっているか (front end / worktree manager) |
| `tests/Integration/CliSmokeTest.php` | 実 `claude` に対する 1 往復 |

unit 群はフェイク CLI を `PATH` に `claude` として置いて回る (`tests/Support/ExecutablePath.php`)。ログイン済み Claude Code は要らない。
