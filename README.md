# logs2tl（FFLogs ツール）

FFLogs のログを読み解くための Web ツール集。FFLogs API v2（GraphQL）から戦闘ログを取得し、
サイトのUIでは出せない切り口で集計・可視化する。

- PHP 8.2 / Laravel 12 / Blade（ビルド不要のインラインJS + Chart.js）
- FFLogs API v2（client credentials / GraphQL）
- コーディング規約: PER Coding Style (PER-CS) / 静的検査は Laravel Pint
- ビルド工程なし（npm 不要）。フロントは CDN の Tailwind とインラインJSのみ

設計の詳細・ファイル間の連動仕様は **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)** を参照。

## 3つのツール

| 画面 | 何をするか |
|---|---|
| 軽減（`GET /mitigation` → `POST /analyze`） | 1つのログをタイムライン化し、ボスの攻撃ごとに「誰が何を撃って何%軽減できていたか」を並べる。バリア・シナジー・効果時間の途切れ（バフブレイク）まで判定する |
| DPS比較（`GET,POST /compare`） | 同じボスの2つのログを A/B で突き合わせ、フェーズ別・分別に火力とローテーションの差を出す。グラフ上で範囲選択すると `GET /compare/range` でその区間だけ再集計する |
| PT検索（`GET,POST /party-search`） | ジョブ構成を指定して一致する討伐ログをランキングから探す。FFLogs のUIはロール人数（タンク2/ヒラ2…）までしか絞れないため、ジョブ単位の照合はAPI経由でしか実現できない |

初めて検索するボスは討伐ログのプール構築に数十秒かかる。これをリクエストの中で待つと
リバースプロキシの読み取りタイムアウト（nginx 既定 60 秒）に掛かるため、**待たずに「準備中」を返し、
取得は応答を返しきったあとに行う**。画面は `GET /party-search/status` をポーリングし、整いしだい自動で再検索する。
事前に温めておくこともできる。

```bash
php artisan fflogs:warm-pool 1085          # エンカウンターIDを指定
php artisan fflogs:warm-pool --zone=76     # ゾーン内の全ボス
```

## セットアップ

```bash
composer install
cp .env.example .env
php artisan key:generate
```

FFLogs API のクライアントを https://www.fflogs.com/api/clients/ で作成し、
発行された Client ID / Secret を `.env` に書く。

```dotenv
FFLOGS_CLIENT_ID=your-client-id
FFLOGS_CLIENT_SECRET=your-client-secret
```

`.env` は `.gitignore` 済み。**認証情報をリポジトリにコミットしないこと。**
値は `config/services.php` 経由で読むので、`php artisan config:cache` した状態でも動く
（`env()` を直接呼ぶとキャッシュ後に null になる）。

```bash
php artisan serve   # http://localhost:8000/
```

## 構成

| ファイル | 役割 |
|---|---|
| `app/Services/FFLogsService.php` | FFLogs GraphQL クライアント。OAuth2 トークン管理、イベントのページング取得、`Http::pool` による並列取得、失敗しても止まらないキャッシュ層 |
| `app/Services/TimelineBuilder.php` | 軽減タイムラインの組み立て。取得→プレイヤー列→軽減列→バフ区間→被弾行→使用マーカー→シナジー窓→死因、の順に積み上げる |
| `app/Services/ReportComparer.php` | A/B比較の集計。フェーズ別の火力・ローテーション・ロス内訳の算出 |
| `app/Services/DeathCheckerBuilder.php` | 死因チェッカー。被弾・バフ・バリア・位置・頭割りマーカーを突き合わせて死因を復元する |
| `app/Http/Controllers/FFLogsController.php` | 入力の検証と、各サービスへの受け渡しだけを行う |
| `app/Http/Controllers/PartySearchController.php` | ジョブ構成の多重集合照合（完全一致／部分一致）とプールの非同期取得 |
| `app/Http/Controllers/IconController.php` | アイコン画像の配信とローカルキャッシュ |
| `app/Support/MitigationSpec.php` | 軽減率・効果時間・リキャスト・シナジー・バリアの仕様表（ゲーム仕様なのでログから取れない） |
| `app/Support/DamageBreakdown.php` | 被弾イベントを実被弾／生ダメージ／軽減率に分解する |
| `app/Support/FFXIVJobs.php` | ジョブ・ロールの定義（唯一の出典）。用途ごとに形を変えて返す投影メソッドを持つ |
| `app/Support/TimelineJobMeta.php` | タイムライン用のジョブ表示情報（`FFXIVJobs` への薄い入口） |
| `resources/views/fflogs/` | 各画面の Blade |

## 設計上の要点

- **トークンとゲームデータは長期キャッシュする。** アクセストークンは50分、ゾーン/エンカウンター一覧は1週間、
  スキルのアイコンや効果範囲などの静的データも長期キャッシュする。1解析あたりのAPI往復を減らすため。
- **キャッシュ書き込みの失敗で処理を止めない。** `FFLogsService::cacheRemember()` は `Cache::put` が失敗しても
  警告ログだけ残して値を返す。キャッシュは高速化のためのものなので、書けないことを致命傷にしない。
- **ランキングの取得は並列化する。** `characterRankings` を200ページ×2メトリクス取得する必要があるため、
  `Http::pool` で40件ずつ波状に投げる（`FFLogsService::RANKING_WAVE`）。ページ数は固定なので、
  並列度を上げてもAPIの消費ポイントは変わらず往復回数だけ減る（1ページあたり 10並列で約0.12秒、40並列で約0.05秒）。
  ランキングに載っていない討伐ログを補う `playerDetails` も、レポート25本ずつのバッチを並列に投げる。
- **重い取得はリクエストの外に出す。** 未キャッシュのボスのプール構築は数十秒かかる。
  `PartySearchController` は `app()->terminating()` に積むことで、応答を返しきったあと
  （`fastcgi_finish_request()` 済み＝クライアントとの接続を切ったあと）に取得する。
  同じボスを同時に温めないよう `Cache::lock` で1プロセスに絞る。
- **ゲーム側の仕様はハードコードせざるを得ない部分がある。** 軽減率・効果時間・クールダウンはログに記録されないので、
  `MitigationSpec` に仕様表として持っている。パッチでスキルが調整されたら直す場所はここ。
- **ジョブの定義は `FFXIVJobs` 1箇所に集約する。** 呼び出し側が欲しい形は場所ごとに違う
  （PT構成検索は FFLogs の fightRankings に合わせた複数形ロール、タイムラインは表示用の単数形と並び順、
  軽減列の一致判定は「表示名→略称」と「略称→type」）。形ごとに表を持つと必ずズレるので、
  実体は1つにして投影メソッドで出し分ける。投影どうしの整合はテストで押さえている。

## 既知の制約

- PT検索で探せるのは**ランキングに載った討伐ログだけ**。絶妖星乱舞での実測では実在するクリアの約38%
  （サンプル69件中26件）。全件を対象にするには全公開レポートを走査してDBに索引を作る必要がある。
- タンク3などの「非標準構成」パーティションはランキング自体がほぼ空なので、この方法では原理的に見つけられない。
- 未キャッシュのボスに対する初回のPT検索は、プールが整うまで結果が出ない（実測で30〜60秒）。
  画面は「準備中」を表示して自動で待つので、リバースプロキシのタイムアウトには掛からない。
- タイムラインに表示されるバフの並び順は FFLogs API が返した順をそのまま使うため、
  キャッシュを取り直すと並びが変わることがある（表示内容は同じ）。

## アイコン画像について

スキル・ジョブのアイコンは初回アクセス時に外部から取得して `public/icons/` に保存し、以降はそこから配信する
（`GET /icons/abilities/{file}`, `GET /icons/jobs/{file}`）。
取得した画像はスクウェア・エニックスの著作物なので、`public/icons/` はリポジトリに含めない（`.gitignore` 済み）。

## テスト・静的検査

```bash
composer test     # php artisan test
composer lint     # vendor/bin/pint --test（PER-CS 準拠チェック）
composer lint:fix # vendor/bin/pint（整形）
```

GitHub Actions で push / PR ごとに lint とテストを回している（`.github/workflows/ci.yml`）。

テストはネットワーク不要。仕様表の整合性（軽減列に載っているのに軽減率の定義が無いスキルの検出）、
被弾イベントの分解、ジョブ表示情報、API がエラーを返したときの画面の振る舞い、そして
**app/ 配下のクラス参照がすべて解決できること**を確認する。
最後の1つは、クラスを別ファイルに切り出したときの `use` 漏れを拾うためのもの
（`php -l` は構文しか見ないので、実行時まで気づけない）。

大きな組み立て処理（`TimelineBuilder`）を分割したときは、分割前のコードと同じリクエストを投げて
出力をバイト比較して確認した。ただし FFLogs が返す `buffs` 配列の順序は取得ごとに変わるため、
比較時はその順序だけ正規化する必要がある。

## ライセンス・権利表記

本リポジトリのコードは作者によるもの。ライセンスは明示していない。
FINAL FANTASY XIV の関連素材・名称の権利は © SQUARE ENIX CO., LTD. に帰属する。
FFLogs は FFLogs の運営者に帰属し、本ツールは非公式・個人利用のためのもの。
