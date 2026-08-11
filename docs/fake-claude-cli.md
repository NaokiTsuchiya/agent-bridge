# フェイク Claude CLI と契約テスト

**ステータス:** 実装済み (issue #5)
**対象:** `tests/Fake/bin/claude` / `tests/Contract/` / `tests/Integration/RealClaudeCliContractTest.php`

---

## 1. これは何で、何ではないか

実行層で確かめたいこと (文脈継続 / プロセスを殺して復帰 / LRU 回収 / 再起動後も同じセッション) は、プロセスを起動しないと取れない。しかし実 `claude` にはログイン・ネットワーク・課金・レート制限が付いて回るので CI では回せない。

そこで **`claude` と同じワイヤプロトコルを話すフェイク CLI** を置き、実行層のテストの大半をそれに対して回す。

> **フェイクは代用であって、仕様の正ではない。正は実 `claude` である。**
>
> 実 CLI の挙動が変わったら、契約テストの **integration 側が落ちる**。落ちたら **フェイクを直す**。
> **実装をフェイクに合わせて歪めてはならない** — フェイクが実 CLI と違う振る舞いをしているなら、それはフェイクの不具合であって、実装が合わせるべき仕様ではない。

フェイクの出力の形は、Claude Code 2.1.223 の実測に由来する (issue #4 の `tests/Event/fixtures/observed-turn.jsonl`、および issue #5 の再実測)。実物のキーをすべて揃えてはおらず、**consumer が読むキーと契約テストが表明するキーだけ**を実物と同名同型にしている。

## 2. 起動のしかた

`tests/Fake/bin/claude` は実行ビット付きの実行可能ファイルで、リポジトリ内のパスをそのまま指定して起動できる。

```bash
FAKE_CLAUDE_HOME=/tmp/fake-home tests/Fake/bin/claude \
  -p --output-format stream-json --verbose --include-partial-messages \
  --session-id 11111111-1111-4111-8111-111111111111 "hello"
```

拡張子を持たないのは意図的で、`mago.toml` の `[source] paths` は `tests` 配下を走査するが拡張子なしのファイルは対象外になる。ロジックは名前空間付きの `tests/Fake/*.php` に置き、この 1 本は autoload して `FakeClaudeCli::main()` を呼ぶだけの shim に留めている (そちらは lint / analyze の網に載る)。

### 再現している挙動

| 状況 | 挙動 |
|---|---|
| 新規 UUID で `--session-id` | 成功 (exit 0) |
| 既存 UUID で `--session-id` (同じ cwd) | stderr `Error: Session ID <UUID> is already in use.` / stdout 空 / **exit 1** |
| 既存 UUID で `--resume` | 成功。前ターンの入力を反映した応答 |
| 不存在 UUID で `--resume` | stderr `No conversation found with session ID: <UUID>` / stdout に `result` 1 行 (`subtype: error_during_execution`, `is_error: true`, `num_turns: 0`) / **exit 1**。**stdin は 1 行も読まない** |
| cwd が違えば同じ UUID でも別セッション | セッションは **cwd と UUID の組**で分かれる |
| `--input-format stream-json` | 常駐。stdin 1 行 = 1 ターン。EOF で **exit 0** |
| それ以外 | 一発実行。位置引数のプロンプトで 1 ターン回して exit 0 |
| `--include-partial-messages` | **付いたときだけ** `stream_event` を出す。`text_delta` の連結は `assistant` の本文と厳密一致する |
| 未知のフラグ (`--verbose`, `--totally-unknown` 等) | 無視して正常動作 |

ターンの出力順は `system/init` (毎ターン再送) → (`stream_event` 群) → (`tool_use` と `tool_result`) → `assistant` (本文) → **`result`** (ターンの最終行)。

**値を取るフラグの一覧は `tests/Fake/FakeArgs.php` にある。** 未知のフラグを真偽フラグとして無視する以上、値を取るフラグを知らないとその値がプロンプトに混入する (`--allowedTools Bash` の `Bash` がプロンプトになる)。新しく値付きフラグを渡すときはこの一覧に足すこと。

## 3. 環境変数

| 変数 | 既定 | 用途 |
|---|---|---|
| `FAKE_CLAUDE_HOME` | `sys_get_temp_dir()/fake-claude-cli` | セッションと記録の保存先。**テストごとに別ディレクトリにする** (同じ根を共有すると、他のテストのセッションが「already in use」として見える) |
| `FAKE_CLAUDE_SCENARIO` | なし | シナリオファイル (JSON) のパス。**設定されているのに読めない / JSON でない場合は exit 2** で止まる (typo が黙って既定動作に落ちないため) |

`FAKE_CLAUDE_HOME` 配下:

```
sessions/<sha1(cwd)>/<uuid>.json   セッション履歴 (そのセッションに送られた入力の配列)
invocations.jsonl                  起動 1 件につき 1 行 + stdin 行ごとに 1 行
turns.jsonl                        ターンの開始/終了 1 件につき 1 行
```

記録の形 (いずれも追記、すべての行が `pid` と `at` を持つ):

```json
{"event":"start","argv":["...","--session-id","..."],"cwd":"/tmp/...","pid":123,"at":1786...}
{"event":"stdin","line":"{\"type\":\"user\",...}\n","pid":123,"at":1786...}
{"session_id":"...","turn":1,"phase":"start","pid":123,"at":1786...}
{"session_id":"...","turn":1,"phase":"end","pid":123,"at":1786...}
```

`turns.jsonl` はターンの直列化 (#8) を確かめるためのもので、**あるターンの `end` と次のターンの `start` の前後関係**が読める唯一の材料である。

## 4. シナリオ制御

```json
{
  "default": { "text": "canned reply" },
  "turns": {
    "1": { "tool": { "name": "Bash", "id": "toolu_1", "result": "hello" } },
    "2": { "is_error": true }
  }
}
```

ターン番号は **1 始まり**で、**そのプロセスのターン**を数える (セッション通算ではない)。ターンごとの指示が `default` を上書きする。

| キー | 型 | 効果 |
|---|---|---|
| `text` | string | 応答本文を固定する |
| `tool` | `{name, id, result}` | `tool_use` の `assistant` 行と、対応する `tool_result` の `user` 行を出す |
| `is_error` | bool | `result` を `is_error: true` / `subtype: error_during_execution` にする |
| `delay_ms` | int | 応答前に待つ (スロットル・タイムアウトの検証) |
| `crash` | int / bool | `result` を出さずにその終了コードで異常終了する (プロセス復帰の検証) |
| `hang` | bool | 応答を返さず居座る (ターンタイムアウトの検証) |

**既定 (シナリオなし) の応答は入力を決定的に反映する**: `fake reply to: <今回の入力>`、履歴があれば ` | previous input: <直前の入力>` が続く。後半があるおかげで、契約テストの「1 ターン目のキーワードが 2 ターン目の応答に含まれる」という表明が、実 CLI と同じ理由 (文脈が残っている) で通る。

## 5. 契約テスト

**同一のテスト本体を、起動するバイナリだけ差し替えて 2 回走らせる。**

| ファイル | 群 | バイナリ |
|---|---|---|
| `tests/Contract/ClaudeCliContractTestCase.php` | (抽象・収集されない) | 本体 |
| `tests/Contract/FakeClaudeCliContractTest.php` | unit | `tests/Fake/bin/claude` |
| `tests/Integration/RealClaudeCliContractTest.php` | integration | `claude` |

確かめているのは 7 項目:

1. 新規 UUID の `--session-id` が成功する
2. 同じ UUID の 2 回目が `--session-id` で失敗し、`--resume` で成功する
3. 存在しない UUID の `--resume` が exit 1 で失敗する
4. 常駐モードで不存在 UUID を `--resume` したとき、stdin に書く前にプロセスが終了する
5. 1 プロセス 2 ターンで文脈が継続する
6. `result` がターン境界になる (ターンの最終行であり、その後もプロセスが生きている)
7. stdin を閉じると exit code 0 になる

**表明に応答テキストの厳密一致は含めない。** 実 `claude` は非決定的な応答を返すので、終了コード / イベント種別の系列 / 指示したキーワードの含有だけで判定する。

### 回し方

```bash
composer test:unit         # フェイク側を含む。ログイン済み Claude Code は不要
composer test:integration  # 実 claude 側。ログインと課金が要る。CI では回さない
```

CI (`.github/workflows/ci.yml`) は unit だけを回す。**integration 側は手元で定期的に回すもの**で、落ちたらフェイクを実 CLI に合わせて直す (2 章の原則)。
