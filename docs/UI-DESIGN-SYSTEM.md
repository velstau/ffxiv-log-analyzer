# Tabler UI（2026-10-25 開発環境適用）

## 基盤と方針

Laravel / Blade / 既存JavaScriptを維持し、partyfinderと同じTabler Core 1.7.1を採用。
`data-bs-theme="dark"` を基本とする。Tabler内蔵のBootstrapだけを使用し、別のBootstrap・Tailwindは読み込まない。
ビルド工程、npm依存、バックエンド、API、DB、解析・検索アルゴリズムは変更していない。
ゲームのアイコンファイルと従来の画面識別用絵文字を維持。操作ツールバーのみTabler IconsのSVGを追加した。

- 公式: https://docs.tabler.io/ / https://preview.tabler.io/
- 配布物: `public/vendor/tabler/1.7.1/`（MIT、配布元・版・SHA-256はSOURCE.json）
- 既存CDN継続: Chart.js 4.4.1、datalabels 2.2.0、modern-screenshot 4.7.0
- 読み込み順: `_ui_head.blade.php` → Tabler CSS → legacy-layout.css → logs-ui.css
- 共通画面: `_tool_nav.blade.php`、`_copyright.blade.php`、`components/ui-icon.blade.php`

## 再利用するルール

カード、フォーム、ボタン、チェック・ラジオ、タブ、凡例ダイアログはTablerの標準クラスを優先する。
背景・文字・境界線・フォーカスには `--tblr-*` と標準の状態色を用いる。
独自の装飾やグラデーションは追加しない。比較グラフの推定値の斜線は、実測との差を示す既存の意味表現として残す。
UI文字は共通環境規約の1rem以上。フォーカスを表示し、クリック可能な結果セルはEnter/Spaceでも開ける。

`legacy-layout.css` は既存の詳細表示・比較結果のレイアウトを維持する小さい移行用アダプター。
旧ユーティリティは `legacy-*` に隔離し、色・コンポーネントはTablerへ移行。
新しい画面ではTablerの標準レイアウトを使い、同じ用途の独自クラスを増やさない。
`hidden` は既存JavaScriptが使用する表示制御なので維持する。

## 軽減表の例外

ここは密度を優先する専用表。全画面を使い、共通ナビゲーションは置かず「新しい解析」で戻る。共有結果・一時保存結果とも `_timeline_header.blade.php` を使用。ヘッダーは2段。上段は左にTOPへのリンク（ツール名）・戦闘名・時間・状態バッジ、右に表示操作・保存と共有・新しい解析をまとめる。下段は解析タブとチェック表示の凡例。ページの種類で構造は変えず、状態バッジと共有操作の文言のみ切り替える。
ゲームアイコン、6状態（mit-used/on/up/cd/x/off）、詳細データとツールチップを維持。
チェック状の表示は解析結果であり、編集可能なチェックボックスには変更しない。

- メタ列: Phase 56 / Time 56 / Action 148 / Base Dmg 74 / Hit Dmg 96px
- プレイヤー列76px、技列32px（1列のみのグループは48px）。1〜2列のグループ見出しはアイコンと名前を縦に配置し、長い名前は折り返す。横幅の余りをAction列に配分しない。
- プレイヤー見出しのジョブ20px、技20px、ジョブグループ28px。
- 表内余白は0〜2px。行は30pxを基準とし、軽減率・バリア等の実データは省略せず必要に応じて行高を増やす。
- 表領域内で縦横スクロール。2段見出しと左メタ列を固定。スマートフォンではBase/Hitの横固定を解除。
- 最下部に横位置スライダーと左右ボタン。表が収まる場合や別タブでは非表示。
- XML/画像出力・表示操作を上部にまとめ、注意書きは下端に置く。

`logs-ui.js` は横幅・固定位置・スクロール同期、キーボード・ダイアログのフォーカスを担当。
解析処理には関与しない。`timeline.js` の既存の関数名・渡されるJSONを維持する。

## 画像保存の互換性修正

旧html2canvasはTablerのoklch色を解釈できず保存に失敗するため、比較画面で既に使っている
modern-screenshotを軽減画面でも使用。全表とプレイヤー詳細の保存関数は共通化した。
表の固定位置とスクロールを複製側で解除し、表示領域外の列も含める。
同一オリジンのアイコンはそのまま使用し、許可された外部ホストの画像だけ既存proxyルートを使用する。
ローカル画像をproxyへ渡して403になる問題も解消。公開ルートやproxyの許可条件は変更しない。

## 検証方法

通常のPHP回帰テストとPER書式検査は既存のPHP 8.4コンテナ・www-dataで実行する。

```bash
docker exec -u www-data -w /var/www/html/vel.works/ff14tools/logs2timeline php8.4 php artisan test
docker exec -u www-data -w /var/www/html/vel.works/ff14tools/logs2timeline php8.4 vendor/bin/pint --test
```

ブラウザ用フィクスチャはAPP_ENV=testingかつPHP組込サーバーでのみ動く。
本番エントリポイントから読み込まれず、実際のFFLogs APIへの通信は禁止する。
セッション・キャッシュ・共有結果は専用のtesting/ui-browserフォルダに隔離。実際の認証キーを入力しない。

```bash
docker exec -u www-data -e APP_ENV=testing -w /var/www/html/vel.works/ff14tools/logs2timeline php8.4 php -S 0.0.0.0:8101 tests/Browser/router.php
# 別ターミナル。接続先IPは環境に合わせる。Playwright Python / Chromeが必要。
python3 tests/Browser/ui_smoke.py http://172.24.0.2:8101/ff14tools/logs2timeline
```

幅1440/390/320pxの入口画面・結果画面、表の横スクロール、プレイヤー表示切替、
フィルター・技/被ダメージ/プレイヤー詳細、XML/PNG保存、PT構成の追加/上限/削除/クリア・ゾーン連動、
DPS比較グラフのドラッグと範囲APIのURL・結果反映、ブラウザエラーを確認する。
成果物は既定で `/tmp/logs2timeline-applied/` に保存。
実キーでのログ解析、PTランキング取得・長時間ポーリング、大量イベントでの画像保存、
外部画像がある実ログ・Safari/Firefoxは別途確認が必要。既存のHTTP/APIテストは実行する。

## 作業範囲と引き継ぎ

開発環境に適用。本番デプロイ・コミット・pushはこの作業に含めない。
変更はviews、publicのUI資産、テスト、本文書とREADME。
適用前バックアップ: `/tmp/logs2timeline-before-tabler-2026-10-25/`。
次は開発URLで実ログを使って見た目と密度を確認し、必要な調整後にリリースする。

### 今回の検証結果

- PHP 8.4: 50テスト / 1,745 assertions 成功。
- Pint: 43ファイル成功。git diff --check成功。
- Chrome: 1440 / 390 / 320pxの7画面、上記操作のブラウザ回帰確認成功。コンソール・JSエラー0。
- 開発nginx: 目次 / 軽減入力 / 比較入力 / APIキー入力と新規CSS・JS・Tabler CSSがHTTP 200。
- フィクスチャ専用サーバーは検証後に停止。通常の開発サーバーは継続稼働。

### 見出しの見切れ修正

技が1〜2列のグループでアイコンと名前が隣のセルにはみ出す問題を修正。
狭いグループは縦並び、1列の技幅は48pxとし、長い名前は折り返す。
複数列の広いグループは32pxの技幅と横並びを維持。
列数が不均等なフィクスチャ（Tank Role、MNK、RPR等）で、見出し内の文字・アイコンの境界をChromeで確認。
1440/390/320pxのブラウザ回帰検証、XML/PNG保存、UIテスト4件・57 assertions、変更フィクスチャのPint検査が成功。

### 数値フォントの統一

軽減画面の数値（時刻・ダメージ・軽減率・詳細ダイアログ）はTabler標準sans-serifに統一。
`--logs-number-font` を共通指定とし、従来のfont-monospaceもこの画面内では同じ書体に揃える。
`tabular-nums lining-nums` で数字の桁幅を揃え、色・太字の意味は維持する。
Chromeの幅1440/390pxで、時刻・Base/Hit・プレイヤー列と動的被ダメージ詳細の計算済みfont-familyが一致し、
数値列にtabular-numsが適用されることを確認。JavaScriptエラー・ページ全体の横はみ出しなし。git diff --check成功。

共有機能は [TIMELINE-SHARING.md](TIMELINE-SHARING.md) を参照。

### 本番反映（2026-10-25）

ユーザーの承認によりUIと共有機能を本番へ反映済み。標準deploy.shを使用し、旧版・既存storageを保持。
本番の入口4画面200、6資産のSHA-256一致、APIキーなしの共有結果表示をChromeの1440/390pxで確認。
詳細はTIMELINE-SHARING.mdと共通_ops/README.mdの本番反映記録を参照。

### Hit Dmgと使用瞬間の表示調整

Hit Dmgは1行目をダメージ数値、2行目を軽減率と+Bに固定。補助情報がない場合は2行目を出力せず、1行分の高さに詰める（他列に複数行の情報がある場合はその高さを維持）。
155,637 / (-38%)+Bのサンプルで1440/390pxの改行・はみ出しなしをChromeで確認。
使用瞬間（mit-used）は緑の塗りと白チェックに変更し、白い光彩を廃止。凡例と表に共通で適用。
バリア破壊の赤、軽減適用・効果中の青は維持する。

### 結果の一時保存と出力整理

全表の画像保存ボタンを廃止（XML・プレイヤー詳細画像保存は維持）。Hit Dmg列を124pxから96pxに縮小。
解析成功後は303で同じブラウザ専用の /results/{id} に移動する。既存の2時間ドラフトを表示に再利用し、再読み込みでAPIを呼ばない。
HTTPのブラウザキャッシュやlocalStorageには保存せず、サーバー側キャッシュとセッションの所有者照合を使用する。
期限切れ・キャッシュ消去・セッション喪失時は自動再解析せず、再解析への案内を表示する。

### モーダルを開く箇所の目印

技名・被ダメージセルに小さな詳細アイコン、既存の詳細を開くセルと見出しに点線下線・ホバー強調・フォーカス枠を統一。
aria-haspopup=dialogと開く内容のツールチップを付加。プレイヤー見出しからも既存のスキル履歴を開ける。
列幅と状態色は維持。狭い見出しは追加アイコンを置かず、下線と境界線で操作可能と示す。
1440/390pxで目印がセル内に収まること、技・被ダメージ・プレイヤーのEnter/Escape操作を確認。UIテスト4件成功。

## 2026-10-26 結果ヘッダーの共通化

共有結果と一時保存結果のヘッダーを `fflogs/_timeline_header.blade.php` に集約。
ブランド/戻り先/新規解析、戦闘情報/状態/表示・保存共有、解析タブの3段構成に整理した。
共有限定だったツールTOP案内と、操作列に重複していた「目次」「新しい解析」を共通上段へ統合。
表の描画・スクロール・解析データ・出力JS・共有権限は変更していない。

検証: TimelineShareTest/UiPageTestの12件（126 assertions）成功。既存の隔離UIルーターで
一時保存結果→共有結果を作成し、1440/1300/390px幅の表示・横はみ出しなし・操作ボタンの表示・JSエラーなしを確認。
画像は `/tmp/logs-header-review/`。一時起動した検証サーバーは停止。本番反映は未実施。
