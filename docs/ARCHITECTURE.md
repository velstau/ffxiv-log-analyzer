# 設計ノート（logs2tl）

このドキュメントは「なぜこの形になっているか」を残すためのもの。
何ができるかは [README](../README.md) を参照。

---

## 1. このアプリが解いている問題

FFLogs は戦闘ログを公開しているが、サイトのUIで見られる切り口は決まっている。
実際に攻略で知りたいのは、そこから一段踏み込んだ次の3つ。

| 知りたいこと | FFLogs のUIでは | このアプリ |
|---|---|---|
| ボスの攻撃1発ごとに、誰の軽減が効いていたか | 出せない（バフ一覧はあるが攻撃と紐づかない） | 軽減タイムライン |
| 2つのログの差が「どのフェーズの、誰の、何の差」なのか | 並べて目視するしかない | A/B比較 |
| このジョブ構成で討伐しているログを探したい | ロール人数までしか絞れない | PT構成検索 |

いずれも **「APIから取れる生データを、人が判断できる単位に組み直す」** のが仕事であり、
処理の大半はデータの整形と突き合わせに費やされる。

---

## 2. 全体の流れ

```
ブラウザ
   │  POST /analyze { url }
   ▼
FFLogsController                      ← 入力の検証と受け渡しだけ
   │  parseUrl / getFightDetails で早期に弾く
   ▼
TimelineBuilder::build()              ← 組み立てのパイプライン
   │
   ├─ FFLogsService                   ← FFLogs GraphQL との唯一の接点
   │     └─ トークン管理・ページング・並列取得・キャッシュ
   │
   ├─ MitigationSpec                  ← ログに無いゲーム仕様（軽減率・効果時間・CD）
   ├─ FFXIVJobs                       ← ジョブ・ロールの唯一の出典
   ├─ DamageBreakdown                 ← 被弾イベント1件の分解
   └─ DeathCheckerBuilder             ← 死亡スナップショットの組み立て
   │
   ▼
view('fflogs.timeline', [...12個のキー])
```

依存の向きは常に **Controller → Service → Support** の一方向。
Support（`MitigationSpec` / `FFXIVJobs` / `DamageBreakdown` / `TimelineJobMeta`）は
何にも依存しない純粋な関数と表だけで構成してあり、そのためテストが書きやすい。

---

## 3. レイヤごとの責務

### `app/Http/Controllers/`

**HTTPのことだけを知っている層。** 計算はしない。

| ファイル | 責務 |
|---|---|
| `FFLogsController` | URLの妥当性チェック、サービスの呼び出し、ビューの選択 |
| `PartySearchController` | 入力の検証、構成の照合、プール未取得時の応答分岐 |
| `IconController` | アイコン画像の配信とローカルキャッシュ |

サービスは `back()` や `redirect()` を返さない。失敗は `App\Exceptions\FFLogsRequestFailed`
で投げ、**ユーザーに何を見せるかはコントローラが決める**。
サービスの戻り値型が `: array` なのにリダイレクトを返すと TypeError になるため、
この境界は `tests/Feature/AnalyzeErrorHandlingTest.php` で固定している。

### `app/Services/`

**外部APIとドメイン計算の層。**

| ファイル | 責務 | 行数の目安 |
|---|---|---|
| `FFLogsService` | FFLogs GraphQL クライアント。ここ以外から API を叩かない | 約1,000 |
| `TimelineBuilder` | 軽減タイムラインの組み立て（14の段に分割） | 約2,000 |
| `ReportComparer` | A/B比較の集計 | 約880 |
| `DeathCheckerBuilder` | 死因スナップショットの復元 | 約900 |

### `app/Support/`

**ゲームの事実と純粋な変換。** 状態を持たず、外部にも触れない。

| ファイル | 責務 |
|---|---|
| `MitigationSpec` | 軽減率・効果時間・リキャスト・シナジー・バリアの仕様表 |
| `FFXIVJobs` | ジョブ・ロールの唯一の出典。用途別の投影メソッドを持つ |
| `TimelineJobMeta` | タイムライン用のジョブ表示情報（`FFXIVJobs` への薄い入口） |
| `DamageBreakdown` | damage イベント1件を実被弾／生ダメージ／軽減率に分解 |

---

## 4. 設計判断とその理由

### 4.1 ゲームの仕様表をコードに持つ

軽減率・効果時間・リキャストは **FFLogs のログに記録されない**。
「リプライザルが入っていた」ことは分かっても「何%軽減されたか」は分からない。
そのためゲーム側の仕様を `MitigationSpec` に表として持つしかない。

パッチでスキルが調整されたら直す場所は `MitigationSpec` だけで済むようにしてある。
表どうしの対応（軽減列に載っているスキルに軽減率の定義があるか等）は
`tests/Unit/MitigationSpecTest.php` が検証するので、追加漏れは気づける。

### 4.2 ジョブ定義を1箇所に集約する

ジョブ情報を必要とする場所は多いが、**欲しい形が場所ごとに違う**。

| 用途 | 欲しい形 | 理由 |
|---|---|---|
| PT構成検索 | `tanks` / `healers` …（複数形） | FFLogs の fightRankings のフィールド名と一致させる必要がある |
| タイムライン表示 | `tank` / `healer` …（単数形）＋並び順 | 表示用 |
| 軽減列の一致判定 | 表示名（"Dark Knight"）→ 略称 | FFLogs が返す表記が場所によって違う |
| 同上 | 略称 → type（"DarkKnight"） | 逆引きも要る |

形ごとに表を作ると必ずズレる。実際、統合前は**7つの表に散らばり、そのうち1つだけ
青魔道士が抜けていた**。現在は `FFXIVJobs` が実体を1つ持ち、投影メソッドで出し分ける。
投影どうしの整合は `tests/Unit/TimelineJobMetaTest.php` が検証する。

### 4.3 重い取得をリクエストの外に出す

PT構成検索の討伐ログプールは、未キャッシュのボスで **30〜60秒** かかる
（characterRankings を200ページ×2メトリクス取得し、さらにランキング外の構成を
playerDetails で補うため）。

これをリクエストの中で待つと、リバースプロキシの読み取りタイムアウト
（nginx 既定 60秒）に掛かって 504 になる。タイムアウト値を上げる対処は、
ボスによって規模が4倍違うため追いつかない。

そこで **応答を返しきったあとに取得する** 形にした。

```
POST /party-search（未キャッシュ）
   │
   ├─→ 「準備中」を 0.07 秒で返す ──→ ブラウザ
   │                                      │ 4秒ごとに
   │   （fastcgi_finish_request 済み）      │ GET /party-search/status
   ▼                                      ▼
app()->terminating() で取得（30〜60秒）   ready:true になったら
   └─→ Cache::lock で多重取得を防止        フォームを自動再送信
```

`public/index.php` は `Response::send()`（＝ `fastcgi_finish_request()`）のあとに
`terminate()` を呼ぶので、ここに積んだ処理は**クライアントとの接続を切ったあと**に走る。
キュー用のワーカープロセスを常駐させずに済む。

### 4.4 キャッシュ書き込みの失敗で処理を止めない

`FFLogsService::cacheRemember()` は `Cache::put` が失敗しても警告ログだけ残して値を返す。

キャッシュは高速化のためのものであって、書けないことを致命傷にする理由がない。
実際、`artisan` を root で実行すると `storage/framework/cache/data` に root 所有の
ディレクトリができ、www-data から書けなくなる事故が起きる。

### 4.5 並列度はページ数ではなく往復回数で効く

ランキング取得は取得ページ数が固定なので、並列度を上げても **API の消費ポイントは変わらず、
往復回数だけ減る**。実測で1ページあたり 10並列で約0.12秒、40並列で約0.05秒。
`FFLogsService::RANKING_WAVE = 40`、`PLAYER_DETAILS_WAVE = 20`。

---

## 5. データの流れ（軽減タイムライン）

`TimelineBuilder::build()` は次の順に積み上げる。各段は独立した private メソッドで、
前の段の出力を受け取って次に渡す。

```
 1. eventQueryIds()            軽減・バリア・シナジーのIDを集めて取得対象を決める
 2. FFLogsService::getEvents() 1回のGraphQLで必要なイベントをまとめて取る
 3. nameResolver()             ID→名前のヘルパー（ペットは飼い主に寄せる）
 4. buildPlayerColumns()       被弾したプレイヤーを列に並べ、出ているジョブを確定
 5. buildMitigationColumns()   表示する軽減列とアイコンを決める
 6. flattenMitigationActionIds() 監視するアクションIDと、撃てるジョブの対応
 7. buildStatusMaxDurations()  バリアの効果時間上限（割れ判定用）
 8. buildEnemyDebuffWindows()  敵デバフの有効区間（発生源つき）
 9. buildActiveBuffWindows()   味方バフの有効区間
10. groupDamageEvents()        被弾を「ボスの一撃」単位の行にまとめる
11. buildPlayerTimelines()     プレイヤーごとの使用スキル時系列
12. markSkillUsage()           各セルに使用/効果中/リキャスト中を付与
13. annotatePhases()           イベントにフェーズ番号を振る
14. buildSynergyWindows()      シナジーをバースト窓単位にまとめる
15. buildDeathSnapshots()      死亡ごとのスナップショット
```

### この順番である理由

- 4 が先なのは、**出ていないジョブの軽減列を出しても意味がない**ため。
  5 のフィルタに 4 の結果（`presentJobs` / `presentRoles`）が要る。
- 8・9 が 10 より前なのは、被弾行を作る時点で「その瞬間に何が効いていたか」を
  引ける状態になっている必要があるため。
- 12 が 10・11 の後なのは、使用マーカーを「最も近い被弾行」に置くため。
  行が確定していないと置き場所が決まらない。

### バフの有効区間を3経路から集める理由

`buildActiveBuffWindows()` は次の3つを統合する。

1. **friendBuffs の applybuff / removebuff をペアにする** — 本来の経路
2. **キャストから窓を合成する** — ロール共通アクション（リプライザル・牽制・アドル等）は
   バフイベントが出ないことがあるため、`MitigationSpec::roleActionDurations()` の
   効果時間ぶんの窓を作る
3. **ペットのバフを飼い主に付け替える** — セラフィム等が付与したバフは発生源がペットになる。
   そのままだとジョブ一致チェックで弾かれ、コンソレイション等が漏れる

---

## 6. ファイル間の連動仕様

「ここを直すとあそこが動く」という関係。片方だけ直すと静かに壊れる箇所。

| 直す場所 | 連動して確認すべき場所 | 壊れ方 |
|---|---|---|
| `MitigationSpec::columns()` にスキル追加 | `MitigationSpec::power()` | 軽減率の定義が無いと**軽減0%として集計される**（`MitigationSpecTest` が検出） |
| `MitigationSpec::barriers()` にバリア追加 | `MitigationSpec::barrierDurations()` | 効果時間が引けず**常に「割れていない」と誤判定**（同テストが検出） |
| `FFXIVJobs::JOBS` にジョブ追加 | 投影メソッド全部 | 自動で追従する（テストが整合を検証） |
| `TimelineBuilder::build()` の返り値キー | `resources/views/fflogs/timeline.blade.php` | `compact()` は文字列でキーを渡すので、**改名しても静的解析に引っかからない** |
| `FFLogsService::getEvents()` のクエリ | `TimelineBuilder` の `$reportData[...]` 参照 | 取得しなくなったデータは `?? []` で静かに空になる |
| クラスを別ファイルへ切り出す | `use` 文 | `php -l` は通るが実行時に `Class not found`（`ClassReferenceTest` が検出） |

`compact()` とビューの結合は言語機能の制約で静的に追えないため、
**ビューに渡すキーを変える場合は必ずテンプレート側も同時に直す**。

---

## 7. キャッシュ戦略

| 対象 | TTL | 理由 |
|---|---|---|
| アクセストークン | 50分 | FFLogs の有効期限より短く取る |
| ゾーン／エンカウンター一覧 | 1週間 | ゲームデータ。パッチ以外で変わらない |
| スキルのアイコン・効果範囲 | 長期 | 同上 |
| 討伐ログのプール | 6時間 | ランキングは常時更新されるが、数時間で大きくは変わらない |
| 戦闘ごとのジョブ構成 | 30日 | 過去のログは変わらない |

キーは取得内容から決定的に作る（例: `fflogs_clear_pool_{encounterId}`）。
アイコン解決のIDは**フィルタ前の全スキル**から集める。レポートのジョブ構成ごとに
キーが変わると、断片化した不完全なマップが固定化されてしまうため。

---

## 8. テストの方針

ネットワークに依存しないものだけを置いている。FFLogs API を叩くテストは
レート制限（3,600 points/hour）を消費し、結果も日々変わるため自動テストには向かない。

| テスト | 何を守っているか |
|---|---|
| `MitigationSpecTest` | 仕様表どうしの対応が崩れていないこと |
| `TimelineJobMetaTest` | ジョブ情報の投影どうしが食い違っていないこと |
| `DamageBreakdownTest` | 被弾の分解（バリア・オーバーキル・生データ欠損の扱い） |
| `ClassReferenceTest` | `use` 漏れ（`php -l` では見つからない） |
| `AnalyzeErrorHandlingTest` | API が失敗したとき画面がエラー表示に落ちること |

### 大きな組み立て処理を変更するとき

`TimelineBuilder` のような巨大な処理を分割・変更する場合、単体テストでは網羅できない。
実際に使った手順は次のとおり。

1. 変更前のコードを別に保持する
2. **同じキャッシュ状態で**同じリクエストを新旧両方に投げ、出力をバイト比較する
3. キャッシュは消さない（消すと API を再取得してレート制限を消費する）
4. FFLogs が返す `buffs` 配列の順序は取得ごとに変わるため、比較時は順序を正規化する

この手順で、切り出し時の `use` 漏れによる 500 エラーを実際に検出できた。

---

## 9. 既知の制約

- **PT構成検索はランキングに載った討伐ログしか探せない。** 絶妖星乱舞での実測では
  実在するクリアの約38%（サンプル69件中26件）。全件を対象にするには全公開レポートを
  走査してDBに索引を作る必要がある。
- **タンク3などの非標準構成は見つけられない。** パーティション自体がほぼ空。
- **バフの並び順は FFLogs API が返した順のまま。** キャッシュを取り直すと並びが変わる
  （表示内容は同じ）。
- **`buildPlayerTimelines()` と `groupDamageEvents()` はまだ大きい**（それぞれ約370行・約325行）。
  内部のループが密に絡んでおり、これ以上の分割は利得が小さいと判断して止めてある。
