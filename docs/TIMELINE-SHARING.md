# 軽減・バリア結果のURL共有

## 利用手順

1. 自分のAPIキーで軽減・バリアの解析を実行する。
2. 結果上部の「URLで共有」を開き、内容を確認して「共有URLを作成する」を押す。
3. 表示されたURLをコピーして相手に渡す。相手はAPIキーもログインも不要。
4. 作成したブラウザで共有URLを開くと、「共有URL・管理」から共有を停止できる。

URL形式は `/ff14tools/logs2timeline/shared/{48文字のランダムID}`。
既に開いていた古い結果ページにはボタンがないため、機能導入後に解析し直す。
解析から2時間を過ぎた場合や準備データがキャッシュから消えた場合も再解析する。

共有は作成時点のスナップショットで、有効期間は30日。
URLを知っている人はプレイヤー名、個人別のダメージ、シナジー・死因などの詳細を閲覧できる。
元のFFLogsレポートを非公開・削除してもスナップショットは連動しない。必要な場合は共有停止する。
共有停止は作成時のセッションが有効な同じブラウザで行う。Cookie削除やセッション期限切れ後は管理できない。
作成者のAPIキー、Client ID/Secret、アクセストークンはURL・共有保存データに含めない。
共有ビューでもフィルター、表示切替、詳細、XML・プレイヤー詳細のPNG出力を利用できる。全表画像保存ボタンは廃止。再集計やFFLogs API呼び出しはしない。

## 構成と保存

| ファイル | 役割 |
|---|---|
| app/Services/TimelineShareStore.php | サーバー生成結果の一時準備、暗号化保存、期限判定、削除 |
| app/Http/Controllers/TimelineShareController.php | 作成・APIキー不要の閲覧・作成者の共有停止 |
| app/Http/Controllers/FFLogsController.php | 解析成功時に共有の一時準備。保存先障害時も結果を表示 |
| resources/views/fflogs/_timeline_share.blade.php | Tablerの共有・管理ダイアログ |
| public/js/timeline-share.js | 作成リクエスト・URLコピー・進行状態・エラー |
| resources/views/fflogs/share-unavailable.blade.php | 期限切れ・存在しない結果・共有停止の表示 |
| app/Console/Commands/PruneTimelineShares.php | 期限切れファイルの掃除 |
| tests/Feature/TimelineShareTest.php | 権限・秘密情報・保存・期限・削除・障害時の検証 |
| tests/Browser/share_smoke.py | 別ブラウザ・APIキーなしの共有操作確認 |

- 通常の検索・解析計算、DB構造・外部API仕様は変更しない。依存ライブラリの追加なし。
- 解析結果の12個の表示用キーだけをサーバー側から準備。クライアントから結果JSON・HTMLを受け取らない。
- 準備データは既存キャッシュに2時間。作成者セッション内のランダム値のハッシュで所有者を照合する。
- 明示的な作成操作で、`local`ディスクの `timeline-shares/{ID}.enc` に認証付き暗号化JSONを保存。
  標準設定では `storage/app/private/timeline-shares/`。公開フォルダ・Gitには置かない。
- 共有結果は通常のキャッシュ消去で失われない。本番deploy.shはstorageを引き継ぐのでデプロイ時も残る。
- 復号には既存APP_KEYが必要。storageとAPP_KEYのバックアップ・復旧方法は従来の運用に従う。
- 作成POSTはCSRF保護・IPあたり10回/分。共有IDと作成者の権限は別で、URLを知るだけでは停止できない。
- 閲覧GETにFFLogs認証ミドルウェアを付けない。no-store / noindex / no-referrerを返す。
- 有効期限を過ぎた結果は必ず表示しない。アクセスされた期限切れファイルはその場で削除。
- `timeline-shares:prune` をdailyスケジュールへ登録。実際に定期掃除するにはサーバー側で既存の
  `php artisan schedule:run` が稼働している必要がある。この機能でOSのcron設定は変更しない。
  スケジューラー未稼働でも閲覧期限は適用されるが、未アクセスの期限切れファイルは掃除まで残る。

```bash
# 開発環境。PHPは指定コンテナ・ユーザーで実行する。
docker exec -u www-data -w /var/www/html/vel.works/ff14tools/logs2timeline php8.4 php artisan timeline-shares:prune
```

## 検証

通常の全PHPテストとPintを実行する。共有操作はAPI通信禁止のフィクスチャサーバーで確認する。
サーバーの起動方法はUI-DESIGN-SYSTEM.mdを参照。
フィクスチャの共有キャッシュ・セッション・結果は `storage/framework/testing/ui-browser/` に隔離。
通常のAPIキー、キャッシュ、共有ファイルを使わない。

```bash
python3 tests/Browser/share_smoke.py http://172.24.0.2:8101/ff14tools/logs2timeline
```

実FFLogsでの再解析、大量イベントの保存時の性能は別途確認する。
開発用の192.168…のURLは同一ネットワークからのみアクセスできる。インターネットへの共有には本番反映が必要。

## 今回の検証結果・適用範囲

- PHP 8.4: 57テスト / 1,804 assertions成功（共有機能7テストを含む）。
- Pint: 全47ファイル成功。git diff --check成功。
- Chrome: 共有作成、別セッション・APIキーなしの閲覧、詳細、XML出力、URLコピー、作成者の停止、停止後404を確認。
- 幅1440/390/320pxの既存7画面・表示切替・フィルター・XML/PNG・PT選択・比較グラフの回帰検証成功。JSエラーなし。
- 開発nginxで共有JSの200と、存在しない共有結果の404/noindexを確認。
- 実APIでの解析・大量イベントの性能、OSの定期実行状態は未確認。
- 2026-10-25に標準deploy.shで本番へ反映済み。コミット・pushは未実施。
- 本番でAPIキー・実ログなしの一時共有結果を使い、匿名閲覧、1440/390px表示、共有URL、noindex/no-store、コンソールエラーなしを確認。
- 一時共有結果は削除し404を確認。検証スクリプトも撤去。直前の版はlogs2timeline.oldへ退避。

## 解析結果の再読み込み

解析POST成功後は303で `/results/{id}` に移動。解析済み結果を2時間、同じブラウザのセッションだけで閲覧できる。
再読み込み時はFFLogs APIを呼ばない。HTTPキャッシュ/localStorageを使わず、共有用の準備データをサーバーキャッシュから読み取る。
期限切れ・キャッシュ消去・セッション期限切れ時は410で再解析を案内する。自動的にはAPIを呼び直さない。
他者への共有にはこの一時URLではなく、従来どおり「URLで共有」で30日有効のURLを作成する。

再読み込み対応の検証：PHP全58テスト/1,814 assertions、Pint全47ファイル、Chromeの1440/390/320px回帰検証成功。
同一結果URLの連続再読み込み、他ブラウザの410、96pxのHit Dmgに(-100%)+Bが収まること、共有作成・停止を確認。

## 外部由来の文字列のエスケープ（2026-10-26）

共有URLで第三者が結果を開くため、FFLogs由来の技名・プレイヤー名・ジョブ・対象・アイコンのパスを、画面に出す前に必ずエスケープする。

- `public/js/timeline.js`: innerHTMLに入れる値は `ddEscape()` を通す（属性値も含む）。判定・照合に使う値は元のまま。
- Blade: onclickなどJSの引数は `'{{ $x }}'` で書かず `{{ \Illuminate\Support\Js::from($x) }}` で渡す。
  `{{ }}` の `&#039;` は属性値の解釈で `'` に戻り、JSの文字列を抜けられる。`'` を含む名前ではクリックも効かなくなっていた。
- 回帰テスト: `UiPageTest::timeline_onclick_arguments_cannot_break_out_of_js_strings`（修正前の版で失敗することを確認）。
- 検証：PHP全59テスト/1,818 assertions、Pint全47ファイル、ui_smoke・share_smoke（1440/390/320px、JSエラーなし）。
- 細工した名前をFFLogsのログに入れられるかは未確認。本番反映は未実施。

## 共有の件数の上限（2026-10-26）

- 1つのブラウザ（セッションの作成者）で有効な共有は10件まで。全体では500件まで（ディスク保護）。超えると429で理由を表示する。
- 同じ解析結果（draft）からは同じ共有URLを返し、ファイルを増やさない。停止後は作り直せる。
- 件数は `timeline-shares/meta/{id}.json`（作成者のハッシュ・draftのハッシュ・期限だけ）で数える。期限切れ・停止・掃除で一緒に消える。
  この索引がない10-26以前の共有は、作成者ごとの件数に入らない（全体の件数には入る）。
- 同時に送信された場合の排他はしていない（作成は1分10回の制限つき。超過は最大でも数件）。
- 検証：PHP全62テスト/1,834 assertions、Pint全48ファイル。
