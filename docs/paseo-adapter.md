# Paseo 実行層の成立条件 (実測)

**ステータス:** 調査のみ (issue #78)。`src/` は変更していない。実装 issue はこの doc をレビューした後に別途切る
**対象:** `paseo` CLI 0.4.0 / daemon 0.4.0 に対する実測と、`AgentRunner` (`src/Runner/AgentRunner.php`) への当てはめ
**実測日:** 2026-08-28

`AgentRunner` は差し替え可能な実行層で、`PersistentCliRunner` / `SpawnCliRunner` の 2 実装で抽象の成立を実証してある (`docs/poc-design.md` 4.4)。第 3 実装として Paseo 管理エージェントを駆動できるかを判断するために、設計を左右する 4 つの未知を実測した。

| # | 問い | 答え (要約) |
|---|---|---|
| 1 | ThreadId ↔ Paseo agent の対応を、永続ストア無しに決定的に取れるか | **取れる。** `--label` で置き `ls --label` で引ける。cwd に依らない。ただし archive すると引けなくなる |
| 2 | `AgentEvent` 5 種に必要な粒度を、paseo のどの口が流せるか | **どの口も 1 種も完全には流せない。** 5 種すべてが「部分的」。テキスト差分の忠実復元は不可能 |
| 3 | worktree の二重管理をどちらに寄せるか | **agent-bridge 側に寄せる。** paseo に作らせるとパスもブランチも衝突時にずれる。既にある worktree を採らせるだけなら安全 |
| 4 | `paseo wait` の idle 判定が `TurnCompleted` の代わりになるか | **ならない。** 成否を持たず、中断と完走が同形になる。境界だけならブロッキング `send` の戻りで取れる |

**逐語断片の読み方:** `$` 付きの行が実行したコマンド、続く行がその実出力である。到着時刻が付いている断片は `ts -s "%.s"` を通したもので、左の数値はコマンド開始からの秒数。

---

## 1. 実測環境

```
$ paseo --version
0.4.0
```

```
$ paseo status
KEY               VALUE
Server ID         srv_HRfS2O-kbXEW
Local Daemon      running
Connected Daemon  reachable
Home              /Users/naoki/.paseo
Listen            127.0.0.1:6767
CLI               0.4.0
Daemon Version    0.4.0
```

**使える provider は `omp` 1 つだけである。**

```
$ paseo provider ls --json
[
  { "provider": "claude",   "label": "Claude",   "status": "unavailable", "enabled": "Disabled" },
  { "provider": "codex",    "label": "Codex",    "status": "unavailable", "enabled": "Disabled" },
  { "provider": "copilot",  "label": "Copilot",  "status": "unavailable", "enabled": "Disabled" },
  { "provider": "opencode", "label": "OpenCode", "status": "unavailable", "enabled": "Disabled" },
  { "provider": "pi",       "label": "Pi",       "status": "unavailable", "enabled": "Disabled" },
  { "provider": "omp",      "label": "Oh My Pi", "status": "available",   "enabled": "Enabled",
    "modes": "Full Access, Write Approval, Always Ask" }
]
```

この doc の動機は「claude 以外の provider (codex 等) にも同じ ThreadId 運用で話せるようになる」ことだったが、**この daemon では codex は今日は動かない**。7 章の結論はこの事実の上に立っている。

probe に使ったエージェントは 2 体だけで、いずれも短いターンを 1〜4 回だけ回して archive した (`omp/anthropic/claude-haiku-4-5`、thinking `minimal`)。

| agent id | ラベル | 用途 |
|---|---|---|
| `bb1c625e-ae63-4417-81c2-f4b7bcc8f252` | `agent-bridge-thread=slack-C123-1700000000.000100` | 問 1 / 問 2 / 問 4 |
| `c6244898-79ce-40a0-8fda-c5f2e8c0f2b5` | `agent-bridge-thread=slack-WS-1` | 問 3 (`--workspace` で cwd を固定できるかの確認) |

## 2. 呼び出し側が Paseo エージェントだと `--cwd` が無視される

これは 4 問のどれでもないが、**問 3 の設計案がここに依存する**ので先に書く。環境に `PASEO_AGENT_CWD` / `PASEO_AGENT_ID` があると `paseo run` は agent-scoped 扱いになり、`--cwd` は効かない。

```
$ paseo run -d --json --title "ab-probe-slack-C123-1700000000.000100" \
    --label "agent-bridge-thread=slack-C123-1700000000.000100" \
    --provider omp/anthropic/claude-haiku-4-5 --thinking minimal \
    --cwd /tmp/paseo-probe-IyCZ \
    "Run the shell command \`echo probe-tool-1\` using your Bash tool, then reply with exactly: PROBE-OK-1"
{
  "agentId": "bb1c625e-ae63-4417-81c2-f4b7bcc8f252",
  "status": "running",
  "provider": "omp",
  "cwd": "/Users/naoki/work/github.com/NaokiTsuchiya/agent-bridge/.claude/worktrees/task-pipeline/gh-78",
  "title": "ab-probe-slack-C123-1700000000.000100"
}
```

要求した `/tmp/paseo-probe-IyCZ` ではなく**呼び出し側の workspace** になった。`inspect` にも呼び出し側が親として入る (`"ParentAgentId": "8e346f6d-..."`)。

`--workspace <id>` は効く:

```
$ paseo run -d --json --title "ab-probe-ws-agent" \
    --label "agent-bridge-thread=slack-WS-1" \
    --provider omp/anthropic/claude-haiku-4-5 --thinking minimal \
    --workspace wks_0fd24ada5bef50a7 \
    "Reply with exactly the output of \`pwd\` and nothing else."
Using workspace wks_0fd24ada5bef50a7
{
  "agentId": "c6244898-79ce-40a0-8fda-c5f2e8c0f2b5",
  "status": "running",
  "provider": "omp",
  "cwd": "/Users/naoki/work/github.com/NaokiTsuchiya/.paseo-worktrees/27frdq1t/ab-probe-setup",
  "title": "ab-probe-ws-agent"
}
```

エージェント自身の答えも同じパスだった (`paseo wait c6244898 --json` の `message` に `/Users/naoki/work/github.com/NaokiTsuchiya/.paseo-worktrees/27frdq1t/ab-probe-setup` が入る)。**cwd を指定する経路は `--workspace` であって `--cwd` ではない。**

## 3. 問 1 — ThreadId ↔ Paseo agent

Paseo の agent id は生成値なので導出できない。代わりに**ラベルが決定的な引き当てに使える**。

```
$ paseo ls --label "agent-bridge-thread=slack-C123-1700000000.000100" --json
[
  {
    "id": "bb1c625e-ae63-4417-81c2-f4b7bcc8f252",
    "shortId": "bb1c625",
    "name": "ab-probe-slack-C123-1700000000.000100",
    "provider": "omp/anthropic/claude-haiku-4-5",
    "thinking": "minimal",
    "status": "idle",
    "cwd": "~/work/github.com/NaokiTsuchiya/agent-bridge/.claude/worktrees/task-pipeline/gh-78",
    "created": "1 minutes ago"
  }
]
```

外れたときは空配列で、例外にも非ゼロ終了にもならない:

```
$ paseo ls --label "agent-bridge-thread=slack-NOPE" --json
[]
$ paseo ls --label "nope=nope" --json >/dev/null; echo $?
0
```

**cwd に依存しない。** `/tmp` から実行しても同じ 1 件が返り、`-g` (全ディレクトリ横断) を付けても結果は変わらなかった。常駐プロセスの cwd がどこであっても引けるということで、これは `AgentRunner` の実装として要る性質である。

名前でも解決する (`inspect` の help は "ID or prefix" しか書いていないが通る):

```
$ paseo inspect "ab-probe-slack-C123-1700000000.000100" --json
{
  "Id": "bb1c625e-ae63-4417-81c2-f4b7bcc8f252",
  "Name": "ab-probe-slack-C123-1700000000.000100",
  ...
```

**ただし `inspect` はラベルを返さない。** キーは `Id / Name / Provider / Model / Thinking / Status / Archived / ArchivedAt / Mode / Cwd / CreatedAt / UpdatedAt / LastUsage / Capabilities / AvailableModes / PendingPermissions / Worktree / ParentAgentId` で、`Labels` は無い。**置いたラベルを読み返す口は `ls --label` だけである。**

### archive すると引けなくなる

`-a` (archived を含める) を付けても返らない:

```
$ paseo archive bb1c625e --json
{
  "agentId": "bb1c625e-ae63-4417-81c2-f4b7bcc8f252",
  "status": "archived",
  "archivedAt": "2026-08-28T01:37:37.139Z"
}
$ paseo ls --label "agent-bridge-thread=slack-C123-1700000000.000100" --json
[]
$ paseo ls -a --label "agent-bridge-thread=slack-C123-1700000000.000100" --json
[]
```

**この非対称は運用上の罠になる。** ラベルによる引き当てが空でも、「そのスレッドのエージェントを一度も作っていない」のか「archive しただけで残っている」のかを区別できない。

### archive 済みへの `send` は成功し、archive が外れる

```
$ paseo send bb1c625e --json "ping"; echo $?
{
  "agentId": "bb1c625e-ae63-4417-81c2-f4b7bcc8f252",
  "status": "completed",
  "message": "Agent completed processing the message"
}
0
$ paseo inspect bb1c625e --json | grep -E '"(Status|Archived)"'
  "Status": "idle",
  "Archived": false,
$ paseo ls --label "agent-bridge-thread=slack-C123-1700000000.000100" --json
[
  {
    "id": "bb1c625e-ae63-4417-81c2-f4b7bcc8f252",
```

**これは `AgentRunner::close()` の意味と一致する** — 「手放すのは機構であって会話ではない。同じスレッドへの後続 `send()` は許され、履歴を保つ」(`src/Runner/AgentRunner.php` の `close()` の docblock)。archive を `close()` に、`send` による復帰を「次のターン」に写せる。

## 4. 問 2 — ストリーミング

流せる口は `paseo logs` と `paseo attach` の 2 つで、**どちらも表示用のテキストしか出さない**。

`logs` の `--json` は無効である。サブコマンドの `--json`、グローバルの `--json`、`-o json` のいずれでも同じテキストが返る (下は `--json` を付けた 1 回分の全出力):

```
$ paseo --json logs bb1c625e
[Omp Notice] OMP info notice from xdev
[Omp Notice] OMP info notice from xdev
[Omp Notice] OMP info notice from xdev
[User] Run the shell command `echo probe-tool-1` using your Bash tool, then reply with exactly: PROBE-OK-1
[Thought] The user wants me to:
1. Run the shell command `echo probe-tool-1` using the Bash tool
2. Reply with exactly: PROBE-OK-1

Let me do this straightforward task.
[Shell] echo probe-tool-1
[Omp Notice] OMP warning notice from advisor
[Omp Notice] OMP warning notice from advisor
PROBE-OK-1
[Thought] The bash command executed successfully and output "probe-tool-1". Now I need to reply with exactly: PROBE-OK-1
```

活動の並びが**時系列でない**ことにも注意が要る。上で `PROBE-OK-1` (応答) が、その応答を決めた `[Thought]` より前に出ている。同じターンを `attach` で見ると順序は逆である:

```
$ paseo attach bb1c625e | ts -s "%.s"
0.549245 [Reasoning] The bash command executed successfully and output "probe-tool-1". Now I need to reply with exactly: PROBE-OK-1
0.549251 PROBE-OK-1
```

**2 つの口が順序について一致しない。** `logs` は表示のために並べ替えているので、この口だけでイベントの順序を決めることはできない。**`AgentEvent` の列は順序が意味を持つ** (`TextDelta` は「前に来たものへ足す断片」で、`TurnCompleted` は最後に来る) ので、実行層が使えるのは `attach` の側になる。

`attach` には `--json` オプションが存在しない (help のオプションは `--host` と `-h` だけ)。

### テキスト差分は届く

`paseo logs -f` の到着時刻:

```
$ paseo logs bb1c625e -f --tail 0 | ts -s "%.s"
0.508299 --- Following logs (no history; Ctrl+C to stop) ---
2.536424 [User] Write a single paragraph of about 120 words about the color blue. No lists, no headings.
3.448400 [Thought] The user wants a
3.744260 [Thought] single paragraph about the color blue, approximately 120 words, with no lists or headings. I should write a natural, flowing paragraph about blue.
4.086231 Blue is one of the most prevalent colors in nature, dominating our skies and oceans with its
4.402383 calming presence. This cool hue has captivated humans throughout history, symbolizing tranquility, wisdom, and trust in many cultures. From the
4.757591 deepest navy depths of the sea to the pale azure of a clear sky, blue encompasses an remarkable spectrum of shades
5.058465 that evoke different emotions and moods. In art and design, blue serves as both a primary color and a versatile accent, capable of creating harmony or bold
5.348110 contrast depending on its application. Psychologically, exposure to blue has been shown to reduce stress and promote relaxation, which is why it's frequently used in therapeutic and med
5.663048 itative spaces. Whether in nature or human creation, blue continues to inspire and soothe, remaining one of the most universally appreciated colors across
5.686628 the world.
```

0.3 秒おきに届き、`med` / `itative` のように**語の途中で切れている**。差分そのものであって、ターン末の一括ではない。

### しかし忠実な復元ができない

**1 断片が 1 行として出るので、断片境界とテキスト中の改行が区別できない。** 上の断片を行連結で戻すと `...therapeutic and med` + 改行 + `itative spaces...` になり、元の文と一致しない。`TextDelta` の契約は「前に来たものへ足す断片」(`src/Event/TextDelta.php`) なので、**この口からは契約を満たす値を作れない**。

区別が接頭辞しかないのも問題になる。推論は断片ごとに `[Thought] ` (logs) / `[Reasoning] ` (attach) が付き直し、応答本文には接頭辞が無い。**本文が `[` で始まると曖昧になる。**

切り詰めはしていない。attach の最長行は 867 文字で、末尾まで (`...across the world.`) 入っていた。

### ツールの開始と完了は attach だけが区別する

8 秒かかるツール (`sleep 8; echo slow-done`) を 1 回流し、2 つの口を同時に追った:

```
$ paseo attach bb1c625e | ts -s "%.s"
4.567818 [Tool: bash] running
12.514857 [Tool: bash] completed
```

```
$ paseo logs bb1c625e -f --tail 0 | ts -s "%.s"
4.568188 [Shell] sleep 8; echo slow-done
12.514928 [Shell] sleep 8; echo slow-done
```

**`logs` は同じ行を 2 回出すだけで、開始と完了が区別できない。** `attach` は状態語 (`running` / `completed`) で区別し、失敗も名乗る。

ただし**速いツールでは `running` が出ず、終端行だけになる**。`echo probe-ok-2 && exit 7` を流したターンで `attach` に現れたツール行は 1 本だけだった:

```
$ paseo attach bb1c625e | ts -s "%.s"
2.650870 [User] Run these two shell commands with your Bash tool, in order: first `echo probe-ok-2`, then `exit 7`. Then reply with exactly: PROBE-DONE-2
5.206019 [Tool: bash] failed
6.461438 [Reasoning] , both commands ran in order. The first command `echo probe-ok-2` produced output, and then the second command `exit 7` caused an
```

`running` に相当する行がこのターンには無い。**開始イベントは保証されない。**

そして**どちらの口も呼び出し id を出さない**。`attach` はツール名 (`bash`) だけ、`logs` はコマンド文字列だけである。`ToolCompleted` は `id` で `ToolStarted` と対応づく設計 (`src/Event/ToolCompleted.php`) なので、id は実行層が発明することになる。

`--filter` も助けにならない:

```
$ paseo logs bb1c625e --filter errors --tail 20
No activity to display.
```

`exit 3` で失敗したツールがある後でもこれである。`--filter tools` は `[Shell] ...` と `[Omp Notice] ...` を返すが、成功/失敗の別は無い。

### attach は接続時に履歴を全再生する

接続した瞬間に過去 4 ターン分が `0.549` 秒台へ一気に来た。ライブ分は 2.6 秒以降で、その間に境目を示す行は無い:

```
$ paseo attach bb1c625e | ts -s "%.s"
0.549205 [Tool: bash] completed
0.549345 [Tool: bash] failed
0.549791 60
0.549798 [User] Write a single paragraph of about 120 words about the color blue. No lists, no headings.
2.650870 [User] Run these two shell commands with your Bash tool, in order: first `echo probe-ok-2`, then `exit 7`. Then reply with exactly: PROBE-DONE-2
```

`logs -f` が出す `--- Following logs (no history; Ctrl+C to stop) ---` に相当するものが `attach` には無い。**再生分とライブ分を分ける材料が無いので、実行層は自分で目印を持つことになる。**

### ターンの終わりを示す行は、どちらの口にも無い

最後の応答断片が届き、そのまま静かになるだけである。

## 5. 問 3 — worktree の二重管理

paseo にも worktree 管理があり、`paseo.json` の `worktree.setup` を含む。**agent-bridge の `WorktreeManager` と役割が重なるので、どちらに寄せるかを実測で決めた。**

### paseo に作らせると、指定した名前どおりにならない

```
$ paseo workspace create --isolation worktree --path /tmp/paseo-probe-IyCZ --mode branch-off \
    --worktree-slug ab-probe-slack-c123 --new-branch agent/slack-c123 --base main --json
{
  "workspaceId": "wks_911c27ada25573bc",
  "project": "paseo-probe-IyCZ",
  "isolation": "worktree",
  "cwd": "/Users/naoki/work/github.com/NaokiTsuchiya/.paseo-worktrees/27frdq1t/ab-probe-slack-c123"
}
$ git worktree list
/private/tmp/paseo-probe-IyCZ                                                             98112bb [main]
/Users/naoki/.../.paseo-worktrees/27frdq1t/ab-probe-slack-c123                            98112bb [agent/slack-c123]
```

`--worktree-slug` は**末尾のディレクトリ名にだけ**反映される。途中の `27frdq1t` はプロジェクトごとの生成値で、**フルパスは slug から導出できない**。

同じ slug でもう一度作ると**べき等でない**:

```
$ paseo workspace create --isolation worktree --path /tmp/paseo-probe-IyCZ --mode branch-off \
    --worktree-slug ab-probe-slack-c123 --new-branch agent/slack-c123 --base main --json
{
  "workspaceId": "wks_c889b46a42b3cba2",
  "cwd": "/Users/naoki/work/github.com/NaokiTsuchiya/.paseo-worktrees/27frdq1t/ab-probe-slack-c123-1"
}
$ git worktree list
/private/tmp/paseo-probe-IyCZ                                                               98112bb [main]
.../.paseo-worktrees/27frdq1t/ab-probe-slack-c123                                           98112bb [agent/slack-c123]
.../.paseo-worktrees/27frdq1t/ab-probe-slack-c123-1                                         98112bb [ab-probe-slack-c123]
$ git branch -a
  ab-probe-slack-c123
  agent/slack-c123
* main
```

**パスに `-1` が足され、`--new-branch agent/slack-c123` は黙って無視されて slug 名のブランチ (`ab-probe-slack-c123`) になった。** 指定した名前が既に使われているとき、paseo はエラーにせず別のものを作る。

これは `docs/poc-design.md` の判断 8 (「worktree も ThreadId から導出。永続ストアが一切不要になる」) と正面から衝突する。導出方式が成り立つのは「同じ ThreadId が常に同じパスに着く」からで、`-1` が足される口はその前提を壊す。

### 既にある worktree を採らせるだけなら、何も動かない

`--isolation local --path` で既存の git worktree を指すと、paseo は新しい worktree を作らずそのパスを採る:

```
$ git worktree add -q -b agent/slack-C9-1 .worktrees/slack-C9-1 main
$ paseo workspace create --isolation local --path "/tmp/paseo-probe2-Nz91/.worktrees/slack-C9-1" \
    --title "ab-probe-local" --json
{
  "workspaceId": "wks_1132dcf31b440b6e",
  "project": "slack-C9-1",
  "isolation": "worktree",
  "cwd": "/tmp/paseo-probe2-Nz91/.worktrees/slack-C9-1"
}
$ git worktree list
/private/tmp/paseo-probe2-Nz91                        27207a6 [main]
/private/tmp/paseo-probe2-Nz91/.worktrees/slack-C9-1  27207a6 [agent/slack-C9-1]
```

`isolation` が `worktree` と報告されるのは、paseo が「そのディレクトリは git worktree である」と判定した結果であって、作ったからではない (`git worktree list` の行数が増えていない)。

同じパスを 2 回採らせると **workspace レコードだけが 2 つになり、パスとブランチは動かない**:

```
$ paseo workspace create --isolation local --path "/tmp/paseo-probe2-Nz91/.worktrees/slack-C9-1" \
    --title "ab-probe-local-2" --json
{
  "workspaceId": "wks_f7ae60b5e559ed08",
  "cwd": "/tmp/paseo-probe2-Nz91/.worktrees/slack-C9-1"
}
$ paseo workspace ls --json | grep slack-C9-1
    "workspaceId": "wks_1132dcf31b440b6e",  "cwd": ".../.worktrees/slack-C9-1"
    "workspaceId": "wks_f7ae60b5e559ed08",  "cwd": ".../.worktrees/slack-C9-1"
```

### archive の破壊力が所有権の境目に沿っている

paseo が作った worktree は、workspace を archive するとディレクトリごと消え、登録も prune される。**ブランチだけは残る**:

```
$ paseo workspace archive wks_0fd24ada5bef50a7 --json
{ "workspaceId": "wks_0fd24ada5bef50a7", "status": "archived", "archivedAt": "2026-08-28T01:38:10.625Z" }
$ git worktree list
/private/tmp/paseo-probe-IyCZ  a8a06d7 [main]
$ git branch -a
  ab-probe-slack-c123
  agent/setup-probe
  agent/slack-c123
* main
```

採らせただけの worktree は archive しても消えない:

```
$ paseo workspace archive wks_1132dcf31b440b6e --json
{ "workspaceId": "wks_1132dcf31b440b6e", "status": "archived", "archivedAt": "2026-08-28T01:44:43.515Z" }
$ paseo workspace archive wks_f7ae60b5e559ed08 --json
{ "workspaceId": "wks_f7ae60b5e559ed08", "status": "archived", "archivedAt": "2026-08-28T01:44:44.145Z" }
$ ls -d /tmp/paseo-probe2-Nz91/.worktrees/slack-C9-1
/tmp/paseo-probe2-Nz91/.worktrees/slack-C9-1
```

**作らせれば消され、採らせれば残る。** スレッドの作業ツリーを消されて困るのは agent-bridge 側なので、この違いが方針を決める。

### `paseo.json` の `worktree.setup` は新しい worktree の中で走る

```
$ cat /tmp/paseo-probe-IyCZ/paseo.json
{
  "worktree": {
    "setup": "date -u +%FT%TZ > SETUP_RAN.txt"
  }
}
$ cat /Users/naoki/.../.paseo-worktrees/27frdq1t/ab-probe-setup/SETUP_RAN.txt
2026-08-28T01:36:50Z
```

このリポジトリの `paseo.json` は `{"worktree":{"setup":"composer install -n"}}` である。**paseo に worktree を作らせない方針を採ると、この `setup` は走らない** — `vendor/` の用意は実行層か、そのスレッドの最初のターン自身が担うことになる。

### `WorktreeManager` が持っていて paseo に無いもの

`src/Worktree/WorktreeManager::worktreeFor()`:

- **解決したパスがベースリポジトリの外を指したら例外** (`str_starts_with($path, "{$base}/")` の検査)。symlink も先に解決してから見る
- ディレクトリだけ消えた状態からの復旧 (`git worktree prune` → 既存ブランチを再チェックアウト)
- **べき等**: 同じ ThreadId は常に同じパスに着く

paseo 側にはどれも無い。`--worktree-slug` の衝突時の挙動 (上) はべき等性そのものを欠く。

## 6. 問 4 — ターン境界

### `paseo wait` は成否を答えない

```
$ paseo wait bb1c625e --json
{
  "agentId": "bb1c625e-ae63-4417-81c2-f4b7bcc8f252",
  "status": "idle",
  "message": "Agent is idle.\nLast 5 activity items:\n[Shell] echo probe-tool-1\n[Omp Notice] OMP warning notice from advisor\n[Omp Notice] OMP warning notice from advisor\nPROBE-OK-1\n[Thought] The bash command executed successfully and output \"probe-tool-1\". Now I need to reply with exactly: PROBE-OK-1"
}
```

`status` は `idle` 固定で、`message` は直近 5 件の活動テキストの寄せ集めである。**ターンが成功したかどうかは書かれていない。**

### 中断されたターンと完走したターンが同形になる

30 秒かかるツールを流し、途中で `stop` した:

```
$ paseo send bb1c625e --no-wait --json "Run this shell command with your Bash tool: \`sleep 30; echo never\`. Then reply DONE."
{
  "agentId": "bb1c625e",
  "status": "sent",
  "message": "Message sent, not waiting for completion"
}
$ paseo stop bb1c625e --json
{
  "stoppedCount": 1,
  "agentIds": [
    "bb1c625e-ae63-4417-81c2-f4b7bcc8f252"
  ]
}
$ paseo wait bb1c625e --json
{
  "agentId": "bb1c625e-ae63-4417-81c2-f4b7bcc8f252",
  "status": "idle",
  "message": "Agent is idle.\nLast 5 activity items:\n...\n[Shell] sleep 30; echo never"
}
```

`attach` 側にも `[Tool: bash] failed` が出るだけで、ターンが打ち切られたことを示す行は無い。**`wait` からは中断と完走が区別できない。**

### 境界そのものはブロッキング `send` の戻りで取れる

```
$ date -u +%FT%T.%NZ
2026-08-28T01:35:05.099023000Z
$ paseo send bb1c625e --json "Run exactly this shell command with your Bash tool: \`sleep 8; echo slow-done\`. Then reply with exactly: PROBE-SLOW-DONE"
{
  "agentId": "bb1c625e-ae63-4417-81c2-f4b7bcc8f252",
  "status": "completed",
  "message": "Agent completed processing the message"
}
$ date -u +%FT%T.%NZ
2026-08-28T01:35:16.891918000Z
```

11.8 秒で戻り、同じターンのストリーム最終イベント (4 章の計測で 13.74 秒、`send` 発行は 2.0 秒の位置 → 11.7 秒) とほぼ一致する。**戻りはターンの終わりを指している。**

ただし `status` は常に `completed` で、**応答本文も per-turn の成否も入らない**。

### エラー経路の exit code

パイプを通さず `$?` を直接見た値:

| コマンド | 未知の id での応答 | exit |
|---|---|---|
| `paseo send` | `{"error":{"code":"SEND_FAILED","message":"Failed to send message: Agent not found: ..."}}` | 1 |
| `paseo attach` | `Error: Failed to attach to agent: Agent not found: 00000000` | 1 |
| `paseo inspect` | `{"error":{"code":"INSPECT_FAILED","message":"Failed to inspect agent: Agent not found: deadbeef"}}` | 1 |
| `paseo wait` | `{"agentId":"deadbeef","status":"error","message":"Agent not found: deadbeef"}` | **0** |
| `paseo ls --label` (0 件) | `[]` | 0 |

**`wait` だけは失敗しても exit 0 である。** JSON の `status` を見ないと取り違える。

## 7. `AgentEvent` 5 種 × 取得可否

`src/Event/` の 5 実装 (`TextDelta` / `ToolStarted` / `ToolCompleted` / `TurnCompleted` / `AgentError`) それぞれについて、paseo のどの口から得られるか。

| `AgentEvent` | 必要なもの | paseo の口 | 可否 |
|---|---|---|---|
| `TextDelta($text)` | 前に来たものへ足す断片 | `attach` (`logs` は並びが時系列でないので使えない) | **部分的。** 断片は 0.3 秒間隔で届き語の途中でも切れるが、1 断片 = 1 行に整形されるので**断片境界とテキスト中の改行が区別できない**。逐語復元は不可 |
| `ToolStarted($name, $id)` | ツール名と呼び出し id | `attach` の `[Tool: <name>] running` | **部分的。** 名前は取れる。**id は無い** (実行層が発番するしかない)。**速いツールでは `running` が出ず開始が落ちる**。`logs` の `[Shell] <command>` は開始と完了で同一行が 2 回出るので使えない |
| `ToolCompleted($id, $success)` | 開始と対応する id と成否 | `attach` の `[Tool: <name>] completed` / `failed` | **部分的。** 成否は取れる。id は無い。`logs --filter errors` は失敗ツールの後でも `No activity to display.` |
| `TurnCompleted($success, $sessionId)` | ターン終了と成否と継続の合図 | ブロッキング `paseo send --json` の戻り | **部分的。** 境界は取れる (戻りが実測でストリーム最終イベントとほぼ一致)。**成否は取れない** — `status` は常に `completed`、`wait` も `idle` 固定で、中断も同形。`sessionId` は Paseo agent id で代用できる |
| `AgentError($message)` | ターンを打ち切る理由 | `send` / `attach` / `inspect` の exit 1 + `error.code` | **可。** ただし `wait` は失敗しても exit 0 なので `status` を見る必要がある |

**5 種のうち「そのまま取れる」ものは `AgentError` 1 つだけである。** 残る 4 種はいずれも実行層が補う必要がある。加えて**イベントの順序を信用できる口は `attach` だけ**で (`logs` は応答を推論より前に置く)、`attach` は機械向けの出力形式を持たない。

## 8. 結論 — PaseoRunner を作る場合の当てはめ方

**作れる。ただし `AgentEvent` の忠実性を 2 点あきらめる契約になる。**

| 契約 | 当てはめ |
|---|---|
| スレッドの同定 | `paseo run --label agent-bridge-thread=<slug>` で置き、`paseo ls --label ... --json` で引く。`slug` は `ThreadDerivation::slug()` をそのまま使えば、導出しかしない性質 (`docs/poc-design.md` 判断 7・8) を保てる。0 件なら新規 `run`、**複数件は取り違えずにエラーにする** (一意性が daemon 側で保証されるかは未実測 — 9 章) |
| cwd | agent-bridge の `WorktreeManager` が `.worktrees/<slug>` を作り、`paseo workspace create --isolation local --path <その絶対パス>` で採らせ、`paseo run --workspace <id>` で載せる。**paseo に worktree を作らせない。** `--cwd` は呼び出し側が Paseo エージェントだと無視されるので使わない (2 章) |
| ストリーム | `paseo attach` を 1 本張る。接続時の履歴全再生を捨てる目印は実行層が持つ (境目の行が無いため)。ツール呼び出し id はターン内カウンタで発番する |
| ターン境界 | ブロッキング `paseo send` の戻りを `TurnCompleted` にする |
| `close()` | `paseo archive`。archive 済みへの `send` が成功して archive が外れる挙動が、`close()` の「手放すのは機構であって会話ではない」と一致する (3 章) |
| `liveProcesses()` | 0。子プロセスを保持しないため (daemon 側のプロセスは agent-bridge の管理下に無い) |

### あきらめる 2 点

1. **`TextDelta` が逐語でない。** 断片境界が改行として観測されるので、`attach` の行を連結したものは元の応答と一致しない。Slack / CLI の出力はそのぶん崩れる。
2. **`TurnCompleted::$success` を正しく立てられない。** 成否の材料が無く、`success: false` を出せるのは `send` が exit 1 のときだけになる。これは `ClaudeCliEventParser` が採っている「読めない結果は成功に格上げしない」規律 (`src/Event/ToolCompleted.php` の docblock) と衝突する — **`AgentRunner` の契約解釈の変更を要求する 1 点であり、実装 issue を切る前に決めるべき論点である。**

これに `ToolStarted` の取りこぼし (速いツールで `running` が出ない) と、呼び出し id の発番が加わる。

### 作らないと判断する場合の根拠

- **動機が今は満たせない。** この doc の出発点は「claude 以外の provider (codex 等) に同じ ThreadId 運用で話す」ことだったが、`paseo provider ls` で `available` なのは `omp` だけである (1 章)。第 3 実装として抽象の成立を実証する価値だけが残る。
- **`paseo` CLI は人間向けの表示層であって機械向けの口ではない。** `logs` の `--json` が効かず、`attach` に `--json` が無く、ツール呼び出し id もターン終端も出ない。`logs` は**応答を推論より前に置く並べ替え**まで行っている (4 章)。**`AgentEvent` に必要な情報は、表示のために整形される過程で落ちている。** これは版が上がれば変わりうる性質で、その版に張り付いた実装になる。
- 上のあきらめる 2 点のうち 2 番目は、`AgentRunner` の既存 2 実装が守っている規律を崩す。**抽象の成立を実証するはずの第 3 実装が、抽象の意味を薄める**という逆立ちが起きる。

**判断の材料としては「daemon が機械向けの口 (JSON ストリーム、ツール呼び出し id、ターン終端、ターンの成否) を出すまで待つ」が最も安い。** それが出れば上の 2 点は消え、当てはめ方は上の表のまま使える。

## 9. 実測できなかったこと

| 何を | なぜできなかったか |
|---|---|
| 同一ラベルを持つエージェント 2 体を daemon が許すか (ラベルの一意性) | 2 体目を同じラベルで作れば分かるが、probe を「短いターン 1〜2 回」に留める方針だったので作っていない。`ls --label` が配列を返す口なので、**実装は複数ヒットを想定した扱いを決める必要がある** (8 章ではエラーにする案を採った) |
| local workspace に `paseo run --workspace` を載せたときの cwd | `--workspace` が cwd を固定することは worktree workspace で実測した (2 章) が、`--isolation local` で採らせた workspace では確かめていない。probe を 2 体に抑えたため。**8 章の cwd 方針はこの 1 点が未確認のまま立っている** |
| `omp` 以外の provider での挙動 | この daemon では `claude` / `codex` / `copilot` / `opencode` / `pi` がすべて `status: unavailable` / `enabled: Disabled` (1 章)。有効化には provider ごとの導入が要り、この調査の範囲を超える |
| 長時間ターン (5 分以上) で `attach` が切れないか | probe の最長ターンは 8 秒のツール 1 本。`docs/slack-adapter.md` 12 章が実 CLI に対して見ているような長時間の維持は見ていない |
| 2 スレッド並行時に `attach` が混線しないか | probe が 2 体とはいえ別々に回しており、同時に 2 本の `attach` を張る形は試していない |
