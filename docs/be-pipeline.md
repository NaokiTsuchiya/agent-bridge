# ポートと Be パイプライン

**ステータス:** 実装済み (issue #10)
**対象:** `src/Chat/` / `src/Pipeline/` / `src/Semantic/` / `src/Thread/ThreadIdFactory.php`

---

## 1. ポート

フロントエンドとの境界は 3 つのインタフェースで、`src/Chat/` にこれだけが置いてある。

```php
interface ChatIngress  { /** @return iterable<IncomingMessage> */ public function listen(): iterable; }
interface ChatEgress   { public function open(ThreadId $thread): StreamHandle;
                         public function status(ThreadId $thread, string $text): void; }
interface StreamHandle { public function append(string $delta): void; public function close(): void; }
```

**ポートが約束するのは「差分を append する」ことだけ** (`poc-design.md` 判断 11)。スロットルの間隔も、どの API で実現するかも、アダプタの責務にしてある — プラットフォームごとに上限も手段も違うので、ここで決めるとどれかが必ず破綻する。

**宣言に特定のフロントエンド固有の語彙 (`thread_ts` / `channel` / `slack`) を書かない。** `tests/Chat/PortDeclarationTest.php` がこの 3 語をポートのソースから探し、見つかったら落ちる。

最初の実装は CLI アダプタ (`poc-design.md` の実装順 10、issue #11)。[`cli-adapter.md`](cli-adapter.md) を参照。

## 2. チェーン

| 段階 | 属性 | 存在が証明すること |
|---|---|---|
| `Pipeline\IncomingMessage` | `#[Be(ResolvedThread::class)]` | 何も検証されていない生の入力 (platform / nativeId / 本文) |
| `Pipeline\ResolvedThread` | `#[Be(CompletedTurn::class)]` | ThreadId が妥当・session_id が導出済み・**worktree が実在する** |
| `Pipeline\CompletedTurn` | (無し = 最終型) | ターンが完走し、イベントが流れきった |

```php
$becoming = $injector->getInstance(BecomingInterface::class);
$turn = $becoming(new IncomingMessage('cli', 'my-experiment', 'what does this repository do?'));
```

呼び出しは 1 回で、途中の遷移はどこにも書かれていない。`Becoming` が `#[Be]` を辿り、最終型に着いたら止まる。

`ResolvedThread` のコンストラクタは順に ThreadId の生成 → session_id の導出 → worktree の生成を行い、3 つを `Thread\ThreadWorkspace` 1 つにまとめて持つ。**どれかが失敗すればオブジェクトは存在しない** ので、「まだ検証されていない ThreadId」「まだ存在しない worktree」を持つ状態を型として表現できない。導出は #3 (`ThreadDerivation`) と #6 (`WorktreeManager`) のもので、ここでは再実装していない。

`ThreadWorkspace` は「この PoC が ThreadId から導出するもの」の全部であり、3 つが常に同じスレッドのものであることを型で担保する (session だけ別スレッドのもの、という状態を作れない)。段階の受け渡しもこれ 1 つ + 本文で済むので、`CompletedTurn` のコンストラクタは `#[Input]` 2 つ + `#[Inject]` 2 つになる。

`CompletedTurn` は `AgentRunner` のイベントを `StreamHandle` へ流す。本文 (`TextDelta`) はそのまま append し、ツール開始 (`ToolStarted`) は本文と混ざらないよう `> 名前` の 1 行として別に append する。`ToolCompleted` は producer がまだ無く、何もしない。

### ThreadId ファクトリ

`ResolvedThread` は ThreadId を `#[Inject] ThreadIdFactory` から作る。#3 の `ThreadDerivation` は static メソッドだけの型で DI に載らないため、この 1 本だけを新設した。中身は `new ThreadId("{$platform}:{$nativeId}")` への委譲で、**書式の規則は `ThreadId` にしか無い**。

例外はひとつだけこのファクトリが持っている: **platform に `:` があれば拒否する。** ThreadId は最初の `:` で割るので、`a:b` を platform として渡すと platform が `a` になり、名乗ったのとは別のプラットフォームのセッション・worktree に着いてしまう。割った後の `ThreadId` からはこれを検出できない。

### `IncomingMessage` はコロン無しの ThreadId 文字列を運べない

`IncomingMessage` は platform と nativeId を別々に持ち、ファクトリが必ず `:` を挟んで合成する。したがって「コロンを含まない ThreadId 文字列」はこの入り口からは作れない (`ThreadIdFactoryTest::joinsThePartsWithOneSeparator` がその不変条件を固定している)。文字列そのものを拒否する側は `ThreadIdTest` (#3) にある。

## 3. セマンティック変数 (`src/Semantic/`)

Be は `#[Input]` 引数の名前から `NaokiTsuchiya\AgentBridge\Semantic\<PascalCase>` を探し、**無ければ `E_USER_NOTICE` を出す** (`vendor/be-framework/be/src/SemanticVariable/SemanticValidator.php`)。`phpunit.xml.dist` は `failOnNotice="true"` なので、登録が無いとチェーンを回すテストが落ちる。

そこで `Platform` / `NativeId` / `Text` / `Workspace` を置いてある (= `ResolvedThread` と `CompletedTurn` の `#[Input]` 引数名の集合)。**検証メソッドは持たない** — 書式の正は `ThreadId` にあり、二重に持つと片方だけ直る日が来る。

> **`ResolvedThread` / `CompletedTurn` に `#[Input]` 引数を足したら、同じ名前のクラスをここに足すこと。** 忘れると通知が出て、テストが落ちて分かる。

## 4. コンパイル済み Injector と `Becoming`

`poc-design.md` が挙げていた「`Becoming` がコンパイル済み Injector で動かないかもしれない」というリスクは、**現れなかった。素の `Injector` への後退はしていない** — `ServeContext` は今までどおりコンパイル済みのままである。

確かめ方は 3 段になっている。

| 何を | どこで |
|---|---|
| `BecomingInterface` がコンパイル済み Injector から解決できる | `tests/Di/CompiledInjectorTest::resolvesBecoming` |
| チェーンが `#[Inject]` する型 (`ThreadIdFactory` / `WorktreeManager`) が同じ Injector から解決できる | 同 `resolvesWhatThePipelineInjects` / `resolvesWorktreeManagement` |
| チェーンが端まで完走する | `tests/Pipeline/BecomingChainTest`、素の `Injector` + `BeModule` |

**3 段目を素の `Injector` でも回しているのは、テストが使うフェイク CLI のパスや使い捨てリポジトリが実行時のパスで、`BakedPathGuard` があるためコンパイル時に焼けないからである** (`src/Di/BaseRepositoryProvider.php` の docblock)。

`CompletedTurn` が注入する `ChatEgress` は #11 で `Di\AppModule` に束縛された (CLI アダプタ)。コンパイル済み Injector からチェーンを端まで回す経路は `tests/Cli/WarmupIsolationTest`、束縛そのものは `tests/Cli/ProductionWiringTest` にある。

**未検証のまま残っているもの:** Swoole の常駐プロセス上で `Becoming` を長時間回したときの挙動 (CLI アダプタはメッセージを流し終えると終了する)。実際に常駐へ載るのは Slack アダプタの issue で、そこで初めて確かめられる。

なお `AppModule` は `ThreadIdFactory` を明示的に束縛している。コンパイル済み Injector は**束縛されていない具象クラスを解決しない**ので (`ClaudeCliSettings` などが同じ理由で束縛されている)、Be がリフレクション経由で頼む型はすべて束縛が要る。
