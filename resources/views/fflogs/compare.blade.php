<!DOCTYPE html>
<html lang="ja" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>A/B比較 - {{ $A['name'] }} - Partyscope</title>
    @include('_ui_head')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/modern-screenshot@4.7.0/dist/index.js"></script>
    <style>
        body { font-size: 1rem; }
        .bar-track { background: #374151; border-radius: 4px; height: 1.1rem; position: relative; overflow: hidden; }
        .bar-a { background: #3b82f6; height: 100%; }
        .bar-b { background: #f43f5e; height: 100%; }
        .potion { width: 1.05rem; height: 1.05rem; vertical-align: middle; display: inline-block; }
        .jobicon { width: 1.35rem; height: 1.35rem; vertical-align: middle; display: inline-block; }
        /* ミラー型A/B比較行（コンパクト・フルネーム両側） */
        /* 名前・数値の列は内容に合わせて広げ、差の列は「実際 +12,345≈」が収まる幅にする（固定幅だと見切れた） */
        .cmp-head, .cmp-row { display: grid; grid-template-columns: minmax(270px, 1.15fr) minmax(0, 1fr) minmax(0, 1fr) minmax(270px, 1.15fr) 132px; align-items: center; gap: 6px; }
        .cmp-row { padding: 2px 0; border-top: 1px solid #1f2937; }
        .cmp-row:hover { background: #1f2937; }
        .bwrap { display: flex; align-items: center; height: 1.15rem; overflow: hidden; }
        .bwrap.a { justify-content: flex-end; }
        .bar-h { height: 100%; min-width: 2px; display: flex; align-items: center; color: #fff; font-size:1rem; font-family: monospace; padding: 0 4px; overflow: hidden; white-space: nowrap; }
        .bar-h.a { background: linear-gradient(90deg, #1d4ed8, #3b82f6); border-radius: 3px 0 0 3px; justify-content: flex-end; }
        .bar-h.b { background: linear-gradient(90deg, #f43f5e, #be123c); border-radius: 0 3px 3px 0; justify-content: flex-start; }
        /* 総ダメージがバーに収まらないときは、バーのすぐ外側に出す（JS の fitBarLabels が .out を付ける） */
        .bar-h { position: relative; }
        .bar-h.out { overflow: visible; }
        .bar-h.out .bl { position: absolute; top: 50%; transform: translateY(-50%); z-index: 1; padding: 0 3px; line-height: 1.15rem;
            background: rgba(15,23,42,.85); border-radius: 3px; }
        .bar-h.a.out .bl { right: 100%; }
        .bar-h.b.out .bl { left: 100%; }
        /* 想定ダメージ（死亡・衰弱・ダメ低下が無かった場合）のゴースト延長バー：斜線＋点線枠で「推定」を明示 */
        .bar-ghost { height: 100%; box-sizing: border-box; display: flex; align-items: center;
            font-size:1rem; font-family: monospace; color: #e2e8f0; padding: 0 3px;
            overflow: hidden; white-space: nowrap; }
        .bar-ghost.a { background: repeating-linear-gradient(-45deg, rgba(59,130,246,.40) 0 5px, rgba(59,130,246,.10) 5px 10px);
            border: 1px dashed rgba(96,165,250,.8); border-right: none; border-radius: 3px 0 0 3px; justify-content: flex-start; }
        .bar-ghost.b { background: repeating-linear-gradient(45deg, rgba(244,63,94,.40) 0 5px, rgba(244,63,94,.10) 5px 10px);
            border: 1px dashed rgba(251,113,133,.8); border-left: none; border-radius: 0 3px 3px 0; justify-content: flex-end; }
        /* ゴーストが狭くて数値が入らない場合に外側に出す想定総ダメージ */
        .gnum { font-size:1rem; font-family: monospace; color: #cbd5e1; padding: 0 2px;
            white-space: nowrap; flex-shrink: 0; border-bottom: 1px dashed #64748b; }
        /* 想定rDPS同士の差（点線下線＝推定値の視覚ルール）。行間は詰める */
        .pot-diff { font-size:1rem; line-height: 1; display: inline-block;
            border-bottom: 1px dashed #64748b; white-space: nowrap; margin-top: -3px; }
        .cmp-row > div:last-child { line-height: 1.15; }
        .dlab { color: #94a3b8; font-size:1rem; margin-right: 2px; }
        /* ロールペア別（タンク/ヒーラー/近接/遠隔）の合計rDPS比較ストリップ */
        .role-strip { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 6px; margin-bottom: 8px; }
        .role-cell { background: rgba(17,24,39,.5); border-radius: 4px; padding: 4px 8px; line-height: 1.35; }
        /* 想定rDPS数値（▶n） */
        .pot { color: #34d399; font-size:1rem; font-family: monospace; }
        /* フェーズカードの画像保存ボタン */
        .shot-btn { color: #9ca3af; font-size:1rem; line-height: 1; padding: 2px 6px; border-radius: 4px; }
        .shot-btn:hover { color: #fff; background: #374151; }
        .shot-btn:disabled { opacity: .5; cursor: wait; }
        .a-info { text-align: right; line-height: 1.15; min-width: 0; }
        .b-info { text-align: left; line-height: 1.15; min-width: 0; }
        /* 名前の行：狭いときは名前だけを省略し、Active% とジョブアイコンは常に見せる */
        .nm { font-size:1rem; color: #e5e7eb; white-space: nowrap; display: flex; align-items: center; gap: 4px; min-width: 0; }
        .a-info .nm { justify-content: flex-end; }
        .nm > * { flex-shrink: 0; }
        .nm > .nm-name { flex-shrink: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
        .rd { font-size:1rem; font-weight: 700; font-family: monospace; }
        .sub { font-size:1rem; color: #9ca3af; font-family: monospace; }
        /* 数値＋バッジ（薬/💀/ダメ低下）行：入りきらないときは隠さず折り返す */
        .stat { display: flex; align-items: center; flex-wrap: wrap; column-gap: 6px; row-gap: 2px; white-space: nowrap; }
        .a-info .stat { justify-content: flex-end; }
        .badges { display: inline-flex; align-items: center; gap: 2px; flex-shrink: 0; }
        .badges .skull { font-size:1rem; line-height: 1; }
        .badges .sec { color: #fb923c; font-size:1rem; font-family: monospace; }
        /* 敵別（ボス別）ダメージ ミラー行 */
        .en-block { margin-top: 6px; }
        .en-head, .en-row { display: grid; grid-template-columns: minmax(200px, 1.3fr) 96px minmax(0, 1fr) minmax(0, 1fr) 96px; align-items: center; gap: 6px; }
        .en-row { padding: 1px 0; }
        .en-name { font-size:1rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        /* アドバイス欄 */
        .advice { border-left: 4px solid; border-radius: 4px; padding: 8px 12px; }
        .advice.low-a { border-color: #3b82f6; background: rgba(59,130,246,0.08); }
        .advice.low-b { border-color: #f43f5e; background: rgba(244,63,94,0.08); }
        .advice.even  { border-color: #6b7280; background: rgba(107,114,128,0.08); }
        .advice ul { margin: 4px 0 0; padding-left: 1.1rem; list-style: disc; }
        .advice li { font-size:1rem; line-height: 1.5; color: #e5e7eb; }
        /* ===== フェーズ内 rDPS推移グラフ：範囲選択パネル ===== */
        .ts-hint { color: #9ca3af; font-weight: 400; font-size:1rem; }
        /* ドラッグ選択中に本文テキストが選択されないように。縦スクロールは残す（横ドラッグ＝範囲選択）。 */
        .phase-ts { cursor: crosshair; user-select: none; -webkit-user-select: none; touch-action: pan-y; }
        .ts-panel:empty { display: none; }
        .ts-panel { margin-top: 6px; background: rgba(17,24,39,.6); border: 1px solid #374151;
            border-left: 3px solid #eab308; border-radius: 4px; padding: 6px 10px; }
        .ts-panel .ts-head { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
        .ts-range { font-family: monospace; font-size:1rem; color: #fde047; font-weight: 700; }
        .ts-len { font-family: monospace; font-size:1rem; color: #9ca3af; }
        .ts-vals { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; font-family: monospace; font-size:1rem; }
        .ts-note { color: #9ca3af; font-size:1rem; margin-top: 2px; }
        .ts-btns { display: flex; gap: 6px; margin-left: auto; }
        .ts-btn { font-size:1rem; padding: 2px 8px; border-radius: 4px; background: #374151; color: #d1d5db; }
        .ts-btn:hover { background: #4b5563; color: #fff; }
        .ts-btn:disabled { opacity: .5; cursor: wait; }
        /* 選択範囲の再集計結果（中身はフェーズカードと同じパーシャルをサーバ側で描画したもの） */
        .ts-detail { margin-top: 6px; border-top: 1px dashed #374151; padding-top: 5px; }
        /* パーティ計の確度表示：概算（実時間割り）→ FFLogs再集計の確定値 */
        .ts-prov { color: #94a3b8; font-size:1rem; font-style: italic; }
        .ts-conf { color: #34d399; font-size:1rem; }
        /* A/Bでフェーズ長が違い、選択範囲を切り詰めたときの注意書き */
        .ts-warn { color: #fcd34d; font-size:1rem; line-height: 1.45; margin-bottom: 4px; }
        /* 選択範囲に来ている敵の攻撃 */
        .ts-atk { margin-top: 5px; font-size:1rem; line-height: 1.5; color: #d1d5db; }
        .ts-atk .atk { display: inline-block; background: rgba(245,158,11,.14); border: 1px solid rgba(245,158,11,.35);
            border-radius: 3px; padding: 0 5px; margin: 1px 3px 1px 0; white-space: nowrap; }
        /* 範囲を選んでカードを差し替えている間の目印。範囲で集計し直していない欄は薄くする */
        .ph-range:empty { display: none; }
        .ph-range { display: inline-block; font-size: 1rem; font-weight: 700; color: #111827; background: #fde047;
            border-radius: 4px; padding: 1px 8px; margin-left: 6px; vertical-align: middle; }
        .ph-scope-note { display: none; color: #fcd34d; font-size: 1rem; margin-bottom: 4px; }
        .phase-card.range-active .ph-scope-note { display: block; }
        .phase-card.range-active .phase-scope { opacity: .45; }
        .phase-card.range-active { box-shadow: inset 0 0 0 2px rgba(253,224,71,.55); }
        .ts-atk .atk .t { color: #94a3b8; font-family: monospace; font-size:1rem; margin-right: 3px; }
        /* 暗い背景で読みにくかった青・赤・見出しを明るくする（Tabler の既定はコントラスト 3.2〜3.6:1） */
        .comparison-page h1.text-primary, .comparison-page h2.text-primary { color: #6ea8fe !important; }
        .comparison-page .text-blue { color: #60a5fa !important; }
        .comparison-page .text-red { color: #fb7185 !important; }
        /* 冒頭の結論 */
        .compare-verdict { border-left: 4px solid #eab308; background: rgba(234,179,8,.08); border-radius: 4px; padding: 10px 14px; font-size: 1.1rem; }
        .compare-help > summary { cursor: pointer; color: #9ca3af; }
        .compare-help[open] > summary { margin-bottom: 6px; }
        /* ===== 狭い画面（スマホ）：横に並べず縦に積む ===== */
        @media (max-width: 767.98px) {
            .compare-sides { grid-template-columns: 1fr !important; }
            .phase-head { flex-wrap: wrap; }
            .phase-head .phase-party { order: 3; flex-basis: 100%; }
            .cmp-head { display: none; }
            .cmp-row { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); grid-template-areas: "ai bi" "ba bb" "df df"; row-gap: 3px; padding: 6px 0; }
            .cmp-row > .a-info { grid-area: ai; }
            .cmp-row > .b-info { grid-area: bi; }
            .cmp-row > .bwrap.a { grid-area: ba; }
            .cmp-row > .bwrap.b { grid-area: bb; }
            .cmp-row > .cmp-diff { grid-area: df; display: flex; justify-content: flex-end; gap: 12px; }
            .en-head > .en-note { display: none; }
            .en-head, .en-row { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); grid-template-areas: "nm nm" "av bv" "ba bb"; row-gap: 2px; }
            .en-head > :first-child, .en-row > .en-name { grid-area: nm; }
            .en-head > .en-av, .en-row > .en-av { grid-area: av; }
            .en-head > .en-bv, .en-row > .en-bv { grid-area: bv; }
            .en-row > .bwrap.a { grid-area: ba; }
            .en-row > .bwrap.b { grid-area: bb; }
            .ts-btns { margin-left: 0; flex-wrap: wrap; }
            .role-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .role-cell > div { white-space: normal !important; }
        }
    </style>
</head>

@php
    $fmt = fn($n) => number_format((int) round($n));
    // 総ダメージを 8.59m / 842k 形式に
    $fmtAmt = function ($n) {
        if ($n >= 1000000) return number_format($n / 1000000, 2) . 'm';
        if ($n >= 1000) return number_format($n / 1000, 0) . 'k';
        return number_format((int) round($n));
    };
    $barpair = function ($a, $b) {
        $m = max($a, $b, 1);
        return [round($a / $m * 100), round($b / $m * 100)];
    };
    $diffSpan = function ($d) use ($fmt) {
        $cls = $d > 0 ? 'text-red' : ($d < 0 ? 'text-blue' : 'text-secondary');
        $sign = $d > 0 ? '+' : '';
        return '<span class="' . $cls . ' font-monospace">' . $sign . $fmt($d) . '</span>';
    };
    // クローズドポジション付与先の表示（名前(uptime%)・複数なら併記）
    $cpFmt = function ($list) {
        if (empty($list)) return '<span class="text-secondary">なし</span>';
        return collect($list)->map(fn($c) => '<span class="text-purple">' . e($c['name']) . '</span>(<span class="text-body">' . $c['pct'] . '%</span>)')->implode(' / ');
    };
    $aPhase = collect($A['phases'])->keyBy('id');
    $bPhase = collect($B['phases'])->keyBy('id');
@endphp

<body class="logs-app comparison-page bg-body text-body legacy-min-h-screen">
    @include('_tool_nav')
    <div class="container-xl py-4 compare-scroll">

        <div class="legacy-flex legacy-items-center legacy-justify-between legacy-flex-wrap legacy-gap-2 legacy-mb-4">
            <div class="legacy-flex legacy-items-center legacy-flex-wrap legacy-gap-2">
                <h1 class="fs-1 fw-bold text-primary m-0">A/B 比較：{{ $A['name'] }}</h1>
                @if ($hideNames ?? false)
                    <span class="badge bg-purple-lt">プレイヤー名を非表示</span>
                @endif
                @if ($isShared ?? false)
                    <span class="badge bg-blue-lt" title="有効期限：{{ $shareExpiresAt }}">共有結果（APIキー不要）</span>
                @elseif ($cachedResult ?? false)
                    <span class="badge bg-secondary-lt" title="比較結果は2時間一時保存されます。再読み込みではAPIを呼び出しません。">結果を一時保存中（2時間）</span>
                @endif
            </div>
            <div class="legacy-flex legacy-items-center legacy-flex-wrap legacy-gap-2">
                @if (($shareDraft ?? false) || ($isShared ?? false))
                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#timeline-share">{{ ($isShared ?? false) ? '共有URL・管理' : 'URLで共有' }}</button>
                @endif
                <a href="{{ route('compare.form') }}" class="btn">別のログを比較</a>
            </div>
        </div>

        {{-- 結論：どちらがどれだけ上か・差が最も大きいフェーズ（説明より先に見せる） --}}
        @php
            $ovA = (float) $A['overall']['partyRdps'];
            $ovB = (float) $B['overall']['partyRdps'];
            $ovGapPct = max($ovA, $ovB) > 0 ? abs($ovB - $ovA) / max($ovA, $ovB) * 100 : 0;
            $worstPhase = null;
            if (count($phaseIds) > 1) {
                foreach ($phaseIds as $pid) {
                    if (isset($aPhase[$pid], $bPhase[$pid])) {
                        $pd = $bPhase[$pid]['summary']['partyRdps'] - $aPhase[$pid]['summary']['partyRdps'];
                        if (!$worstPhase || abs($pd) > abs($worstPhase[1])) {
                            $worstPhase = [$pid, $pd];
                        }
                    }
                }
            }
        @endphp
        <div class="compare-verdict legacy-mb-4">
            @if ($ovGapPct < 1.5)
                パーティ rDPS は<strong>ほぼ互角</strong>（差 {!! $diffSpan($ovB - $ovA) !!}・{{ number_format($ovGapPct, 1) }}%）。
            @else
                <strong class="{{ $ovB > $ovA ? 'text-red' : 'text-blue' }}">{{ $ovB > $ovA ? 'B' : 'A' }} パーティ</strong>が
                パーティ rDPS で <strong class="font-monospace">{{ $fmt(abs($ovB - $ovA)) }}</strong>（{{ number_format($ovGapPct, 1) }}%）上回っています。
            @endif
            @if ($worstPhase && abs($worstPhase[1]) >= 1)
                差が最も大きいのは <a href="#phase-{{ $worstPhase[0] }}" class="text-yellow fw-bold">Phase {{ $worstPhase[0] }}</a>（B−A {!! $diffSpan($worstPhase[1]) !!}）。
            @endif
        </div>

        {{-- ヘッダ：A vs B 概要 --}}
        <div class="legacy-grid legacy-grid-cols-2 legacy-gap-4 legacy-mb-6 compare-sides">
            <div class="bg-surface rounded legacy-p-4 legacy-border-l-4 border-blue">
                <div class="text-blue fw-bold fs-3">A パーティ</div>
                <div class="text-secondary">{{ $A['zoneName'] }} ／ 戦闘時間 {{ gmdate('i:s', (int) $A['durationSec']) }}</div>
                <div class="legacy-mt-2 text-secondary">パーティ rDPS：
                    <span class="text-white font-monospace fs-2">{{ $fmt($A['overall']['partyRdps']) }}</span>
                </div>
            </div>
            <div class="bg-surface rounded legacy-p-4 legacy-border-l-4 border-red">
                <div class="text-red fw-bold fs-3">B パーティ</div>
                <div class="text-secondary">{{ $B['zoneName'] }} ／ 戦闘時間 {{ gmdate('i:s', (int) $B['durationSec']) }}</div>
                <div class="legacy-mt-2 text-secondary">パーティ rDPS：
                    <span class="text-white font-monospace fs-2">{{ $fmt($B['overall']['partyRdps']) }}</span>
                </div>
            </div>
        </div>

        {{-- ===== 全体サマリ ===== --}}
        <section class="legacy-mb-8">
            <h2 class="fs-2 fw-bold text-primary legacy-mb-2 legacy-border-b border-secondary legacy-pb-1">全体サマリ（戦闘全体）</h2>
            <details class="compare-help bg-surface rounded legacy-p-3 legacy-mb-3 text-base text-body legacy-leading-relaxed">
                <summary>指標の意味（総ダメージ・DPS・rDPS）</summary>
                <div><span class="text-body fw-bold">総ダメージ</span>：パーティ全員がボスに与えたダメージの合計。</div>
                <div><span class="text-body fw-bold">パーティDPS（raw）</span>：総ダメージ ÷ 戦闘時間。各自が画面上で実際に出した素のダメージ量の合計レート。</div>
                <div><span class="text-body fw-bold">パーティrDPS</span>：シナジー（バフ/デバフによる貢献）を配分し直した<span class="text-yellow">実力指標</span>。
                    バフを配る職（学者・詩人・踊り子など）は配った分が加算され、貰った側は割り引かれる。
                    「素のダメージ」では損する支援職も正当に評価でき、A/Bの地力比較に向く。</div>
            </details>
            @php
                $metrics = [
                    ['総ダメージ', $A['overall']['partyTotal'], $B['overall']['partyTotal']],
                    ['パーティ DPS（raw）', $A['overall']['partyDps'], $B['overall']['partyDps']],
                    ['パーティ rDPS', $A['overall']['partyRdps'], $B['overall']['partyRdps']],
                ];
            @endphp
            <div class="legacy-space-y-3">
                @foreach ($metrics as [$lbl, $av, $bv])
                    @php [$aw, $bw] = $barpair($av, $bv); $diff = $bv - $av; @endphp
                    <div class="bg-surface rounded legacy-p-3">
                        <div class="legacy-flex legacy-justify-between text-base text-body legacy-mb-1">
                            <span class="fw-bold">{{ $lbl }}</span>
                            <span class="text-secondary">差(B−A)：{!! $diffSpan($diff) !!}</span>
                        </div>
                        <div class="legacy-flex legacy-items-center legacy-gap-2 legacy-mb-1">
                            <span class="legacy-w-10 text-blue text-base">A</span>
                            <div class="bar-track legacy-flex-1"><div class="bar-a" style="width: {{ $aw }}%"></div></div>
                            <span class="legacy-w-28 text-end font-monospace text-base">{{ $fmt($av) }}</span>
                        </div>
                        <div class="legacy-flex legacy-items-center legacy-gap-2">
                            <span class="legacy-w-10 text-red text-base">B</span>
                            <div class="bar-track legacy-flex-1"><div class="bar-b" style="width: {{ $bw }}%"></div></div>
                            <span class="legacy-w-28 text-end font-monospace text-base">{{ $fmt($bv) }}</span>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- 戦闘全体の敵別（ボス別）被ダメージ --}}
            @if (!empty($A['overall']['enemies']) || !empty($B['overall']['enemies']))
                <div class="bg-surface rounded legacy-p-3 legacy-mt-3">
                    <div class="text-base fw-bold text-body legacy-mb-1">敵別ダメージ（戦闘全体・どの敵にどれだけ入れたか）</div>
                    @include('fflogs._compare_enemies', ['aEnemies' => $A['overall']['enemies'] ?? [], 'bEnemies' => $B['overall']['enemies'] ?? []])
                </div>
            @endif
        </section>

        {{-- ===== フェーズ別 DPS推移（線グラフ） ===== --}}
        @php
            $chartLabels = array_map(fn($pid) => 'P' . $pid, $phaseIds);
            $chartA = array_map(fn($pid) => isset($aPhase[$pid]) ? round($aPhase[$pid]['summary']['partyRdps']) : null, $phaseIds);
            $chartB = array_map(fn($pid) => isset($bPhase[$pid]) ? round($bPhase[$pid]['summary']['partyRdps']) : null, $phaseIds);
            // 想定rDPS（死亡・衰弱・ダメ低下が無かった場合）。実測との差が0.5%超のフェーズがあれば点線で描く
            $chartApot = array_map(fn($pid) => isset($aPhase[$pid]) ? round($aPhase[$pid]['summary']['partyPotentialRdps'] ?? 0) : null, $phaseIds);
            $chartBpot = array_map(fn($pid) => isset($bPhase[$pid]) ? round($bPhase[$pid]['summary']['partyPotentialRdps'] ?? 0) : null, $phaseIds);
            $showPot = false;
            foreach ($phaseIds as $i => $pid) {
                if (($chartApot[$i] ?? 0) > ($chartA[$i] ?? 0) * 1.005 || ($chartBpot[$i] ?? 0) > ($chartB[$i] ?? 0) * 1.005) { $showPot = true; break; }
            }
        @endphp
        @if (count($phaseIds) > 1)
            <section class="legacy-mb-6">
                <h2 class="fs-2 fw-bold text-primary legacy-mb-2 legacy-border-b border-secondary legacy-pb-1">フェーズ別 DPS推移</h2>
                <div class="bg-surface rounded legacy-p-4" style="height: 320px;">
                    <canvas id="phaseChart"></canvas>
                </div>
            </section>
        @endif

        {{-- ===== フェーズ別 火力（個人別 A/B 差） ===== --}}
        <section class="legacy-mb-8">
            <h2 class="fs-2 fw-bold text-primary legacy-mb-2 legacy-border-b border-secondary legacy-pb-1">フェーズ別 火力（個人別 A/B 比較）</h2>
            <details class="compare-help text-base text-secondary legacy-mb-3"><summary>表の見方（バー・アイコン・想定値）</summary><span class="text-blue">A=左/青</span>・<span class="text-red">B=右/赤</span>のバーが中央で対向（バー長＝rDPS、長い方が優勢／バー上＝総ダメージ）。
                名前の（）はActive%、大きい数字＝rDPS、小さい字＝DPS・aDPS。<img class="potion" src="{{ asset('icons') }}/abilities/020000-020710.png" onerror="this.style.display='none'">=薬・💀=死亡・<img class="potion" src="{{ asset('icons') }}/abilities/215000-215520.png" onerror="this.style.display='none'"><span class="text-orange">ns</span>=ダメージ低下デバフn秒被弾。ダメージ低下の低下率はコンテンツごとに違い、ログに記録されないため、出典のある一部のコンテンツ以外は -{{ round(\App\Support\MitigationSpec::DD_REDUCTION * 100) }}% と仮定しています（アイコンのホバーで確認）。
                <span class="pot">▶n</span>と<span class="text-body" style="border: 1px dashed #94a3b8; padding: 0 3px;">斜線バー</span>=死亡・衰弱・ダメ低下が無かった場合の想定rDPS（ホバーで内訳）。差はB−A。同ジョブ不在時は<span class="text-yellow">同ロール</span>で対戦（≈）。</details>

            @foreach ($phaseIds as $pid)
                @php
                    $as = $aPhase[$pid]['summary'] ?? null;
                    $bs = $bPhase[$pid]['summary'] ?? null;
                    $ard = $as['partyRdps'] ?? 0; $brd = $bs['partyRdps'] ?? 0;
                    [$paw, $pbw] = $barpair($ard, $brd);

                    // 個人別バー等はパーシャル側で組み立てる（範囲選択時と同じ描画を使うため）
                    $aList = array_values($as['players'] ?? []);
                    $bList = array_values($bs['players'] ?? []);
                @endphp

                <div class="phase-card bg-surface rounded legacy-p-3 legacy-mb-3" data-pid="{{ $pid }}" id="phase-{{ $pid }}">
                    <div class="phase-head legacy-flex legacy-items-center legacy-justify-between legacy-gap-3 legacy-mb-2">
                        <div class="fw-bold text-yellow fs-3 legacy-shrink-0">Phase {{ $pid }}
                            <span class="ph-range"></span>
                            <button type="button" class="btn btn-sm shot-btn" title="このフェーズを画像として保存">画像保存</button>
                        </div>
                        {{-- パーティ計（1行コンパクト） --}}
                        <div class="phase-party legacy-flex-1 legacy-flex legacy-items-center legacy-gap-2 text-base" data-a="{{ $as ? $ard : '' }}" data-b="{{ $bs ? $brd : '' }}">
                            <span class="text-blue font-monospace legacy-shrink-0">A {{ $as ? $fmt($ard) : '—' }}</span>
                            <div class="bar-track legacy-flex-1"><div class="bar-a" style="width: {{ $paw }}%"></div></div>
                            <span class="text-secondary legacy-shrink-0">計rDPS</span>
                            <div class="bar-track legacy-flex-1"><div class="bar-b" style="width: {{ $pbw }}%"></div></div>
                            <span class="text-red font-monospace legacy-shrink-0">B {{ $bs ? $fmt($brd) : '—' }}</span>
                        </div>
                        <div class="ph-diff text-base text-secondary legacy-shrink-0 text-end">差 {!! $diffSpan($brd - $ard) !!}
                            @php
                                $aPr = $aPhase[$pid]['summary']['partyPotentialRdps'] ?? $ard;
                                $bPr = $bPhase[$pid]['summary']['partyPotentialRdps'] ?? $brd;
                            @endphp
                            @if ($as && $bs && ($aPr > $ard * 1.005 || $bPr > $brd * 1.005))
                                <div title="想定rDPS（死亡・衰弱・ダメ低下が無い場合）同士の差 B−A"><span class="pot-diff">想定 {!! $diffSpan($bPr - $aPr) !!}</span></div>
                            @endif
                        </div>
                    </div>
                    <div class="ph-scope-note">選択範囲を集計し直したのは、パーティ計・不足側の分析・ロールペア別・個人別です。薬・クローズドポジション・敵別ダメージはフェーズ全体の値のままです。</div>
                    @php $apg = $aPhase[$pid]['potionGainDps'] ?? 0; $bpg = $bPhase[$pid]['potionGainDps'] ?? 0; @endphp
                    @if ($apg > 0 || $bpg > 0)
                        <div class="phase-scope text-base text-yellow legacy-mb-1 text-end">薬の推定上乗せ：A +{{ $fmt($apg) }} ／ B +{{ $fmt($bpg) }} DPS相当</div>
                    @endif
                    <div class="phase-scope text-base text-secondary legacy-mb-1">💃 クローズドポジション付与先
                        <span class="text-blue">A</span>: {!! $cpFmt($aPhase[$pid]['closedPos'] ?? []) !!}　／
                        <span class="text-red">B</span>: {!! $cpFmt($bPhase[$pid]['closedPos'] ?? []) !!}</div>

                    {{-- 敵別（ボス別）ダメージ：このフェーズで各敵にどれだけ入れたか --}}
                    @if (!empty($aPhase[$pid]['enemies']) || !empty($bPhase[$pid]['enemies']))
                        <div class="phase-scope legacy-mb-2">
                            <div class="text-base fw-bold text-body legacy-mb-1">🎯 敵別ダメージ（このフェーズ）</div>
                            @include('fflogs._compare_enemies', ['aEnemies' => $aPhase[$pid]['enemies'] ?? [], 'bEnemies' => $bPhase[$pid]['enemies'] ?? []])
                        </div>
                    @endif

                    {{-- フェーズ内 rDPS推移（10秒毎・パーティ計。横軸=フェーズ開始からの経過時間） --}}
                    @php
                        $aP = $aPhase[$pid] ?? null;
                        $bP = $bPhase[$pid] ?? null;
                        $aSer = $aP['series'] ?? [];
                        $bSer = $bP['series'] ?? [];
                        // 範囲指定の個人別rDPS再集計API（/compare/range）に渡す識別子と、フェーズ開始の絶対時刻
                        $tsSrc = [
                            'a' => $aP ? ['code' => $A['code'] ?? null, 'fight' => $A['fightId'] ?? null, 'start' => $aP['startMs'], 'end' => $aP['endMs']] : null,
                            'b' => $bP ? ['code' => $B['code'] ?? null, 'fight' => $B['fightId'] ?? null, 'start' => $bP['startMs'], 'end' => $bP['endMs']] : null,
                        ];
                    @endphp
                    @if (count($aSer) > 1 || count($bSer) > 1)
                        <div class="legacy-mb-2 ts-wrap">
                            <div class="text-base fw-bold text-body legacy-mb-1 legacy-flex legacy-items-baseline legacy-gap-2 legacy-flex-wrap">
                                <span>⏱ フェーズ内 rDPS推移（10秒毎・パーティ計）</span>
                                <span class="ts-hint">グラフを左右にドラッグ＝範囲選択 ／ ダブルクリック＝解除・全体表示</span>
                            </div>
                            <div class="bg-body rounded legacy-p-2" style="height: 320px;">
                                <canvas class="phase-ts"
                                    data-a='@json($aSer)' data-b='@json($bSer)'
                                    data-a1='@json($aP['secDmg'] ?? [])' data-b1='@json($bP['secDmg'] ?? [])'
                                    data-ea='@json($aP['enemyCasts'] ?? [])' data-eb='@json($bP['enemyCasts'] ?? [])'
                                    data-src='@json($tsSrc)'></canvas>
                            </div>
                            <div class="ts-panel"></div>
                        </div>
                    @endif

                    {{-- 不足側アドバイス／ロールペア別／個人別ミラーバー（範囲選択時と共通のパーシャル）。
                         グラフで範囲を選ぶと、中身を範囲の再集計結果に差し替える（解除で戻す） --}}
                    <div class="phase-blocks">
                        @include('fflogs._compare_blocks', [
                            'aList' => $aList, 'bList' => $bList,
                            'adv' => $advice[$pid] ?? null, 'scope' => 'このフェーズ',
                        ])
                    </div>
                </div>
            @endforeach
        </section>

    </div>

    @if (count($phaseIds) > 1)
        <script>
            (function () {
                const ctx = document.getElementById('phaseChart');
                if (!ctx || typeof Chart === 'undefined') return;
                const datasets = [
                    { label: 'A パーティ rDPS', data: @json($chartA), borderColor: '#3b82f6', backgroundColor: '#3b82f6', tension: 0.25, spanGaps: true, pointRadius: 4, borderWidth: 2 },
                    { label: 'B パーティ rDPS', data: @json($chartB), borderColor: '#f43f5e', backgroundColor: '#f43f5e', tension: 0.25, spanGaps: true, pointRadius: 4, borderWidth: 2 },
                ];
                @if ($showPot)
                // 想定rDPS（死亡・衰弱・ダメ低下が無かった場合の推定）＝点線
                datasets.push(
                    { label: 'A 想定（死亡・デバフ無し）', data: @json($chartApot), borderColor: 'rgba(96,165,250,.65)', backgroundColor: 'rgba(96,165,250,.65)', borderDash: [7, 5], tension: 0.25, spanGaps: true, pointRadius: 3, pointStyle: 'rectRot', borderWidth: 1.5 },
                    { label: 'B 想定（死亡・デバフ無し）', data: @json($chartBpot), borderColor: 'rgba(251,113,133,.65)', backgroundColor: 'rgba(251,113,133,.65)', borderDash: [7, 5], tension: 0.25, spanGaps: true, pointRadius: 3, pointStyle: 'rectRot', borderWidth: 1.5 },
                );
                @endif
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: @json($chartLabels),
                        datasets: datasets
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { labels: { color: '#e5e7eb', font: { size: 16 } } },
                            tooltip: { titleFont: { size: 16 }, bodyFont: { size: 16 }, callbacks: { label: (c) => c.dataset.label + '：' + (c.parsed.y ?? 0).toLocaleString() } }
                        },
                        scales: {
                            x: { ticks: { color: '#cbd5e1', font: { size: 16 } }, grid: { color: '#374151' } },
                            y: { ticks: { color: '#cbd5e1', font: { size: 16 }, maxTicksLimit: 6, callback: (v) => v.toLocaleString() }, grid: { color: '#374151' }, title: { display: true, text: 'パーティ rDPS', color: '#9ca3af', font: { size: 16 } } }
                        }
                    }
                });
            })();
        </script>
    @endif

    {{-- バー上の数値が入りきらないとき：総ダメージはバーの外へ、想定値はゴーストの外（gnum）へ出す。幅が変わるたびにやり直す --}}
    <script>
        (function () {
            const textWidth = el => { const r = document.createRange(); r.selectNodeContents(el); return r.getBoundingClientRect().width; };
            function fitBarLabels() {
                document.querySelectorAll('.gnum.js-moved').forEach(g => g.remove());
                document.querySelectorAll('.bar-ghost[data-text]').forEach(g => { g.textContent = g.dataset.text; g.removeAttribute('data-text'); });
                document.querySelectorAll('.bar-h.out').forEach(b => b.classList.remove('out'));
                document.querySelectorAll('.bwrap .gnum').forEach(g => { g.style.marginLeft = g.style.marginRight = ''; });
                document.querySelectorAll('.bwrap').forEach(w => {
                    const bar = w.querySelector('.bar-h'), bl = bar && bar.querySelector('.bl');
                    if (!bl || !w.clientWidth) return; // 非表示の間は測れない（開いたときにやり直す）
                    const out = textWidth(bl) + 8 > bar.clientWidth;
                    bar.classList.toggle('out', out);
                    const ghost = w.querySelector('.bar-ghost');
                    const lw = out ? bl.getBoundingClientRect().width : 0;
                    if (ghost && ghost.textContent.trim() && textWidth(ghost) + 8 + lw > ghost.clientWidth) {
                        const g = document.createElement('span');
                        g.className = 'gnum js-moved';
                        g.title = ghost.title;
                        g.textContent = ghost.textContent.replace(/[▶◀]/g, '');
                        ghost.dataset.text = ghost.textContent;
                        ghost.textContent = '';
                        w.classList.contains('a') ? w.insertBefore(g, w.firstChild) : w.appendChild(g);
                    }
                    // 外に出した総ダメージがゴーストより長いときは、その先の想定値（gnum）を押し出して重ねない
                    const gnum = w.querySelector('.gnum'), room = lw + 4 - (ghost ? ghost.getBoundingClientRect().width : 0);
                    if (out && gnum && room > 0) gnum.style[w.classList.contains('a') ? 'marginRight' : 'marginLeft'] = room + 'px';
                });
            }
            let t;
            window.addEventListener('resize', () => { clearTimeout(t); t = setTimeout(fitBarLabels, 150); });
            document.addEventListener('toggle', fitBarLabels, true);
            if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitBarLabels);
            fitBarLabels();
            window.fitBarLabels = fitBarLabels; // 範囲選択でカードを差し替えたあとにも呼ぶ
        })();
    </script>

    {{-- フェーズカードの画像保存（modern-screenshotでカードをPNG化してダウンロード） --}}
    <script>
        (function () {
            const fightName = @json($A['name'] ?? 'compare');
            document.querySelectorAll('.shot-btn').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const card = btn.closest('.phase-card');
                    if (!card || typeof modernScreenshot === 'undefined') return;
                    btn.disabled = true;
                    const old = btn.textContent;
                    btn.textContent = '⏳';
                    btn.style.visibility = 'hidden'; // ボタン自体は画像に写さない
                    try {
                        const dataUrl = await modernScreenshot.domToPng(card, { scale: 2, backgroundColor: '#1f2937' });
                        const a = document.createElement('a');
                        a.href = dataUrl;
                        a.download = ('AB比較_' + fightName + '_Phase' + card.dataset.pid + '.png').replace(/[\\/:*?"<>|]/g, '_');
                        a.click();
                    } catch (e) {
                        console.error('capture failed', e);
                        alert('画像の保存に失敗しました: ' + e.message);
                    } finally {
                        btn.style.visibility = '';
                        btn.disabled = false;
                        btn.textContent = old;
                    }
                });
            });
        })();
    </script>

    {{-- フェーズ内 rDPS推移（10秒毎）チャート描画＋範囲選択＋敵攻撃マーカー --}}
    <script>
        (function () {
            if (typeof Chart === 'undefined') return;

            const BW = 10;                                   // グラフのバケット幅（秒）＝サーバ側 SERIES_BUCKET_SEC と揃える
            const RANGE_URL = @json(route('compare.range')); // 選択範囲の個人別rDPS再集計API
            // 範囲の再集計の出どころ。live＝FFLogsに問い合わせ（一時結果）、shared＝共有を作るときに取り置いた
            // 10秒区間の集計から出す（レポート番号も閲覧者のキーも使わない）、none＝取り置きのない古い共有（概算だけ）
            const RANGE_MODE = @json(!($isShared ?? false) ? 'live' : ((!empty($A['rangeIndex']) || !empty($B['rangeIndex'])) ? 'shared' : 'none'));
            const SHARE_RANGE_URL = @json(($isShared ?? false) && isset($shareId) ? route('timeline-shares.range', $shareId) : null);
            const CAN_REFETCH = RANGE_MODE !== 'none';
            const HIDE_NAMES = @json((bool) ($hideNames ?? false));
            const fmtT = s => Math.floor(s / 60) + ':' + String(Math.round(s % 60)).padStart(2, '0');
            const nf = n => Math.round(n).toLocaleString();
            const esc = t => String(t).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
            const sign = n => (n > 0 ? '+' : n < 0 ? '−' : '±') + nf(Math.abs(n));

            /** 1秒バケットから [t0, t1) 秒のパーティrDPS（＝与ダメ合計÷秒数）を出す。
             *  パーティ合計のrDPSはraw合計と一致する（シナジー配分はパーティ内ゼロサム）ため合計÷秒数でよい。
             *  分母は実時間なので、範囲内にボス無敵などの不可侵時間があると公式値よりやや低く出る。 */
            function rangeRdps(sec1, t0, t1) {
                if (!sec1 || !sec1.length) return null;
                const i0 = Math.max(0, Math.floor(t0));
                const i1 = Math.min(sec1.length, Math.ceil(t1));
                if (i1 <= i0) return null;
                let sum = 0;
                for (let i = i0; i < i1; i++) sum += sec1[i];
                return { total: sum, sec: i1 - i0, rdps: sum / (i1 - i0) };
            }

            /** 選択範囲内の敵キャストを抜き出す（同じ技の連続は名前でまとめる） */
            function castsIn(list, t0, t1) {
                const out = [], seen = new Set();
                (list || []).forEach(c => {
                    if (c.t < t0 || c.t > t1) return;
                    const k = c.n + '@' + Math.floor(c.t / 3);
                    if (seen.has(k)) return;
                    seen.add(k);
                    out.push(c);
                });
                return out;
            }

            /** 選択範囲の帯（ドラッグ中も含む）を線の下に描く */
            const rangeSelPlugin = {
                id: 'rangeSel',
                beforeDatasetsDraw(chart) {
                    const sel = chart.$sel;
                    if (!sel || !chart.chartArea) return;
                    const ca = chart.chartArea, sx = chart.scales.x, ctx = chart.ctx;
                    const x0 = Math.max(ca.left, sx.getPixelForValue(sel.t0));
                    const x1 = Math.min(ca.right, sx.getPixelForValue(sel.t1));
                    if (x1 <= x0) return;
                    ctx.save();
                    ctx.fillStyle = 'rgba(234,179,8,0.13)';
                    ctx.fillRect(x0, ca.top, x1 - x0, ca.bottom - ca.top);
                    ctx.strokeStyle = 'rgba(234,179,8,0.8)';
                    ctx.lineWidth = 1;
                    ctx.beginPath();
                    ctx.moveTo(x0 + .5, ca.top); ctx.lineTo(x0 + .5, ca.bottom);
                    ctx.moveTo(x1 - .5, ca.top); ctx.lineTo(x1 - .5, ca.bottom);
                    ctx.stroke();
                    ctx.restore();
                }
            };

            /** 敵の攻撃（キャスト開始）をプロット領域の上に目盛りとして描く。A=上段/B=下段 */
            const castMarkPlugin = {
                id: 'castMarks',
                afterDatasetsDraw(chart) {
                    const rows = chart.$castRows;
                    if (!rows || !chart.chartArea) return;
                    const ca = chart.chartArea, sx = chart.scales.x, ctx = chart.ctx;
                    ctx.save();
                    rows.forEach((row, ri) => {
                        if (!row.list.length) return;
                        // 凡例は下に置いてあるので、プロット領域の上パディング（padding.top=44）に2段で描く
                        const y = ca.top - 40 + ri * 18;
                        ctx.strokeStyle = row.color;
                        ctx.lineWidth = 2;
                        ctx.beginPath();
                        row.list.forEach(c => {
                            const x = sx.getPixelForValue(c.t);
                            if (x < ca.left - 1 || x > ca.right + 1) return;
                            ctx.moveTo(Math.round(x) + .5, y);
                            ctx.lineTo(Math.round(x) + .5, y + 12);
                        });
                        ctx.stroke();
                        ctx.fillStyle = row.color;
                        ctx.font = 'bold 16px sans-serif';
                        ctx.textAlign = 'left';
                        ctx.textBaseline = 'alphabetic';
                        ctx.fillText(row.label, ca.right + 4, y + 13);
                    });
                    ctx.restore();
                }
            };

            document.querySelectorAll('canvas.phase-ts').forEach(cv => {
                const read = k => { try { return JSON.parse(cv.dataset[k] || 'null') || null; } catch (e) { return null; } };
                const A = read('a') || [], B = read('b') || [];
                const A1 = read('a1') || [], B1 = read('b1') || [];
                const EA = read('ea') || [], EB = read('eb') || [];
                const SRC = read('src') || {};
                const panel = cv.closest('.ts-wrap').querySelector('.ts-panel');
                // 範囲の再集計結果で差し替えるフェーズカードの欄（解除で戻すため、元の中身を控えておく）
                const card = cv.closest('.phase-card');
                const cardParts = {
                    party: card.querySelector('.phase-party'),
                    diff: card.querySelector('.ph-diff'),
                    blocks: card.querySelector('.phase-blocks'),
                };
                const cardOrig = {};
                Object.keys(cardParts).forEach(k => { cardOrig[k] = cardParts[k] ? cardParts[k].innerHTML : ''; });
                const cardBadge = card.querySelector('.ph-range');

                // 線形時間軸：実時間に比例した位置に点を打つ（A/Bでフェーズ長・端数終端が違っても歪まない）
                const toXY = arr => arr.map(p => ({ x: p.t, y: p.v }));
                const maxT = Math.max(A.length ? A[A.length - 1].t : 0, B.length ? B[B.length - 1].t : 0);
                const baseMin = 0, baseMax = maxT + 8;
                // 点が増えた（10秒毎）ぶん、数値ラベルは重なるので目盛りは30秒毎・ラベルは非表示が既定
                const tickStep = maxT > 240 ? 60 : 30;

                const chart = new Chart(cv, {
                    type: 'line',
                    data: {
                        datasets: [
                            { label: 'A', data: toXY(A), borderColor: '#3b82f6', backgroundColor: '#3b82f6', tension: 0.3, spanGaps: true, pointRadius: 2, pointHoverRadius: 5, borderWidth: 2 },
                            { label: 'B', data: toXY(B), borderColor: '#f43f5e', backgroundColor: '#f43f5e', tension: 0.3, spanGaps: true, pointRadius: 2, pointHoverRadius: 5, borderWidth: 2 },
                        ]
                    },
                    plugins: [rangeSelPlugin, castMarkPlugin],
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        animation: false, // 画像保存時に途中状態が写らないように
                        // 上パディングは敵攻撃マーカー（2段）の描画枠。凡例は下に置いて上を空ける。
                        layout: { padding: { top: 44, bottom: 4, left: 12, right: 32 } },
                        interaction: { mode: 'index', axis: 'x', intersect: false },
                        plugins: {
                            datalabels: { display: false }, // 10秒毎では点が密で数値ラベルは潰れるため出さない
                            legend: { position: 'bottom', align: 'start', labels: { color: '#e5e7eb', font: { size: 16 }, boxWidth: 18, padding: 10 } },
                            tooltip: {
                                titleFont: { size: 16 }, bodyFont: { size: 16 }, footerFont: { size: 16 },
                                callbacks: {
                                    title: (items) => items.length ? (fmtT(Math.max(0, items[0].parsed.x - BW)) + '〜' + fmtT(items[0].parsed.x)) : '',
                                    label: (c) => c.dataset.label + '：' + nf(c.parsed.y ?? 0) + ' rDPS',
                                    // その10秒で来ている敵の攻撃
                                    footer: (items) => {
                                        if (!items.length) return '';
                                        const x = items[0].parsed.x;
                                        const lines = [];
                                        [['A', EA], ['B', EB]].forEach(([lab, list]) => {
                                            const names = [];
                                            castsIn(list, x - BW, x).forEach(c => { if (!names.includes(c.n)) names.push(c.n); });
                                            if (names.length) lines.push('▼' + lab + 'の被攻撃: ' + names.slice(0, 6).join(' / ') + (names.length > 6 ? ' 他' + (names.length - 6) : ''));
                                        });
                                        return lines;
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                type: 'linear', min: baseMin, max: baseMax,
                                ticks: { stepSize: tickStep, color: '#9ca3af', font: { size: 16 }, callback: (v) => fmtT(v) },
                                grid: { color: '#1f2937' },
                                title: { display: true, text: 'フェーズ開始からの経過時間', color: '#9ca3af', font: { size: 16 } }
                            },
                            y: { beginAtZero: true, ticks: { color: '#9ca3af', font: { size: 16 }, maxTicksLimit: 5, callback: (v) => v.toLocaleString() }, grid: { color: '#374151' } }
                        }
                    }
                });
                chart.$castRows = [
                    { label: 'A敵', color: 'rgba(147,197,253,.85)', list: EA },
                    { label: 'B敵', color: 'rgba(253,164,175,.85)', list: EB },
                ];
                chart.$sel = null;
                chart.render();

                // ===== 範囲選択（左右ドラッグ） =====
                const durSec = Math.max(A1.length, B1.length, maxT);
                let dragging = false, anchorT = 0;
                const tAt = clientX => {
                    const r = cv.getBoundingClientRect();
                    const ca = chart.chartArea;
                    const px = Math.min(Math.max(clientX - r.left, ca.left), ca.right);
                    return chart.scales.x.getValueForPixel(px);
                };

                cv.addEventListener('pointerdown', e => {
                    if (e.button !== 0 || !chart.chartArea) return;
                    const r = cv.getBoundingClientRect();
                    const y = e.clientY - r.top;
                    if (y > chart.chartArea.bottom + 4) return; // 軸ラベル・凡例の上でのドラッグは無視
                    dragging = true;
                    reqSeq++; // 前の範囲の応答が、ドラッグ中・ドラッグ後のカードに入らないように
                    anchorT = tAt(e.clientX);
                    chart.$sel = { t0: anchorT, t1: anchorT };
                    try { cv.setPointerCapture(e.pointerId); } catch (err) { /* 非対応環境は無視 */ }
                    chart.render();
                });
                cv.addEventListener('pointermove', e => {
                    if (!dragging) return;
                    const t = tAt(e.clientX);
                    chart.$sel = { t0: Math.min(anchorT, t), t1: Math.max(anchorT, t) };
                    chart.render();
                });
                const endDrag = () => {
                    if (!dragging) return;
                    dragging = false;
                    let { t0, t1 } = chart.$sel;
                    t0 = Math.max(0, Math.round(t0));
                    t1 = Math.min(durSec, Math.round(t1));
                    if (t1 - t0 < 2) { clearSel(); return; } // クリック相当＝選択解除
                    chart.$sel = { t0, t1 };
                    chart.render();
                    renderPanel();
                };
                cv.addEventListener('pointerup', endDrag);
                cv.addEventListener('pointercancel', () => { dragging = false; });
                cv.addEventListener('dblclick', () => { clearSel(); resetZoom(); });

                function clearSel() {
                    chart.$sel = null;
                    reqSeq++; // 通信中の範囲の応答を捨てる
                    panel.innerHTML = '';
                    restoreCard();
                    chart.render();
                }

                /** フェーズカードを範囲の再集計結果に差し替える（見出しのパーティ計・差と、下の3ブロック） */
                function applyCard(res, label) {
                    const a = res.a ? res.a.partyRdps : null, b = res.b ? res.b.partyRdps : null;
                    if (cardParts.party) cardParts.party.innerHTML = partyHtml(a, b);
                    if (cardParts.diff) cardParts.diff.innerHTML = diffHtml(a, b, res);
                    if (cardParts.blocks) cardParts.blocks.innerHTML = res.html || '';
                    if (cardBadge) cardBadge.textContent = '選択範囲 ' + label;
                    card.classList.add('range-active');
                    if (window.fitBarLabels) window.fitBarLabels();
                }
                function restoreCard() {
                    if (!card.classList.contains('range-active')) return;
                    Object.keys(cardParts).forEach(k => { if (cardParts[k]) cardParts[k].innerHTML = cardOrig[k]; });
                    if (cardBadge) cardBadge.textContent = '';
                    card.classList.remove('range-active');
                    if (window.fitBarLabels) window.fitBarLabels();
                }
                // 見出しの1行。Blade 側（.phase-party・.ph-diff）と同じ形で組む
                function partyHtml(a, b) {
                    const m = Math.max(a || 0, b || 0, 1);
                    return '<span class="text-blue font-monospace legacy-shrink-0">A ' + (a != null ? nf(a) : '—') + '</span> ' +
                        '<div class="bar-track legacy-flex-1"><div class="bar-a" style="width: ' + Math.round((a || 0) / m * 100) + '%"></div></div> ' +
                        '<span class="text-secondary legacy-shrink-0">計rDPS</span> ' +
                        '<div class="bar-track legacy-flex-1"><div class="bar-b" style="width: ' + Math.round((b || 0) / m * 100) + '%"></div></div> ' +
                        '<span class="text-red font-monospace legacy-shrink-0">B ' + (b != null ? nf(b) : '—') + '</span>';
                }
                function diffSpan(d) {
                    const cls = d > 0 ? 'text-red' : d < 0 ? 'text-blue' : 'text-secondary';
                    return '<span class="' + cls + ' font-monospace">' + (d > 0 ? '+' : '') + nf(d) + '</span>';
                }
                function diffHtml(a, b, res) {
                    let h = '差 ' + diffSpan((b || 0) - (a || 0));
                    const ap = res.a ? res.a.partyPotentialRdps : null, bp = res.b ? res.b.partyPotentialRdps : null;
                    if (a != null && b != null && ap != null && bp != null && (ap > a * 1.005 || bp > b * 1.005)) {
                        h += '<div title="想定rDPS（死亡・衰弱・ダメ低下が無い場合）同士の差 B−A"><span class="pot-diff">想定 ' + diffSpan(bp - ap) + '</span></div>';
                    }
                    return h;
                }
                function zoomTo(t0, t1) {
                    chart.options.scales.x.min = Math.max(baseMin, t0 - 2);
                    chart.options.scales.x.max = Math.min(baseMax, t1 + 2);
                    chart.options.scales.x.ticks.stepSize = (t1 - t0) > 120 ? 30 : 10;
                    chart.update();
                }
                function resetZoom() {
                    chart.options.scales.x.min = baseMin;
                    chart.options.scales.x.max = baseMax;
                    chart.options.scales.x.ticks.stepSize = tickStep;
                    chart.update();
                }

                // ===== 選択範囲パネル =====
                // 1秒バケットからの即時値（実時間割り）をまず出し、続けてFFLogsで再集計した
                // 公式基準の値に差し替える。実時間割りはボス不可侵（ダウンタイム）があると
                // 最大10%以上低く出るため、表示する確定値は必ずFFLogs側を使う。
                let reqSeq = 0;
                const rangeCache = new Map();

                function renderPanel() {
                    const sel = chart.$sel;
                    restoreCard(); // 前の範囲の値をカードに残さない（新しい範囲の結果が届いたら差し替える）
                    if (!sel) { panel.innerHTML = ''; return; }
                    const { t0, t1 } = sel;
                    const ra = rangeRdps(A1, t0, t1), rb = rangeRdps(B1, t0, t1);

                    // 選択範囲に来ている敵の攻撃
                    let atkHtml = '';
                    [['A', EA, 'text-blue'], ['B', EB, 'text-red']].forEach(([lab, list, cls]) => {
                        const cs = castsIn(list, t0, t1);
                        if (!cs.length) return;
                        const chips = cs.slice(0, 24).map(c =>
                            '<span class="atk" title="' + esc(c.s || '') + '"><span class="t">' + fmtT(c.t) + '</span>' + esc(c.n) + '</span>'
                        ).join('');
                        atkHtml += '<div><span class="' + cls + '">' + lab + ' 被攻撃</span> ' + chips +
                            (cs.length > 24 ? '<span class="text-secondary">…他' + (cs.length - 24) + '件</span>' : '') + '</div>';
                    });

                    panel.innerHTML =
                        '<div class="ts-head">' +
                            '<span class="ts-range">' + fmtT(t0) + ' – ' + fmtT(t1) + '</span>' +
                            '<span class="ts-len">' + (t1 - t0) + 's</span>' +
                            '<span class="ts-vals">' + valsHtml(ra && ra.rdps, rb && rb.rdps, true) + '</span>' +
                            '<span class="ts-btns">' +
                                '<button type="button" class="btn ts-btn" data-act="zoom">この範囲にズーム</button>' +
                                '<button type="button" class="btn ts-btn" data-act="reset">全体表示</button>' +
                                '<button type="button" class="btn ts-btn" data-act="clear">解除</button>' +
                            '</span>' +
                        '</div>' +
                        (atkHtml ? '<div class="ts-atk">' + atkHtml + '</div>' : '') +
                        '<div class="ts-detail"></div>';

                    panel.querySelectorAll('.ts-btn').forEach(b => b.addEventListener('click', () => {
                        const act = b.dataset.act;
                        if (act === 'zoom') zoomTo(t0, t1);
                        else if (act === 'reset') resetZoom();
                        else if (act === 'clear') { clearSel(); resetZoom(); }
                        else if (act === 'retry') loadDetail(t0, t1);
                    }));
                    loadDetail(t0, t1);
                }

                /** A/Bのパーティ rDPS と差を1行に整形。provisional=true なら「概算」と分かる見た目にする。 */
                function valsHtml(a, b, provisional, res) {
                    const out = [];
                    if (a != null) out.push('<span class="text-blue">A ' + nf(a) + '</span>');
                    if (a != null && b != null) out.push('<span class="text-secondary">vs</span>');
                    if (b != null) out.push('<span class="text-red">B ' + nf(b) + '</span>');
                    if (a != null && b != null) {
                        const d = b - a;
                        out.push('<span class="' + (d > 0 ? 'text-red' : d < 0 ? 'text-blue' : 'text-secondary') + '">差 ' + sign(d) + '</span>');
                    }
                    if (!out.length) out.push('<span class="text-secondary">—</span>');
                    out.push('<span class="text-secondary text-base">rDPS</span>');
                    // 想定rDPS（死亡・衰弱・ダメ低下が無い場合）同士の差。フェーズ見出しの表記と揃える。
                    if (res && res.a && res.b) {
                        const ap = res.a.partyPotentialRdps, bp = res.b.partyPotentialRdps;
                        if (ap != null && bp != null && (ap > a * 1.005 || bp > b * 1.005)) {
                            out.push('<span class="pot-diff" title="想定rDPS（死亡・衰弱・ダメ低下が無い場合）同士の差 B−A">' +
                                '<span class="dlab">想定</span>' + sign(bp - ap) + '</span>');
                        }
                    }
                    out.push(provisional
                        ? '<span class="ts-prov">' + (CAN_REFETCH ? '概算・再集計中…' : '概算') + '</span>'
                        : '<span class="ts-conf">FFLogs基準</span>');
                    return out.join(' ');
                }

                /** 選択範囲をA/Bまとめて1リクエストで集計する（同じ範囲は使い回して問い合わせを増やさない） */
                async function fetchRange(win) {
                    const q = new URLSearchParams();
                    if (HIDE_NAMES) q.set('anon', '1'); // フェーズカードと同じ別名で返してもらう
                    Object.keys(win).forEach(k => {
                        const s = SRC[k];
                        q.set(k + '[code]', s.code);
                        q.set(k + '[fight]', s.fight);
                        q.set(k + '[start]', s.start + win[k].t0 * 1000);
                        q.set(k + '[end]', Math.min(s.end, s.start + win[k].t1 * 1000));
                    });
                    const key = q.toString();
                    if (rangeCache.has(key)) return rangeCache.get(key);
                    const r = await fetch(RANGE_URL + '?' + key, { headers: { 'Accept': 'application/json' } });
                    // 401 は API キーの問題（サーバーが説明文を JSON で返す）。それ以外の失敗は状態コードを出す
                    const j = r.ok ? await r.json() : await r.json().catch(() => ({})).then(e => ({ error: e.error || ('HTTP ' + r.status) }));
                    if (!j.error) rangeCache.set(key, j);
                    return j;
                }

                /** 共有：選択範囲（フェーズ開始からの秒）を取り置きから集計してもらう。名前を伏せた共有は別名で保存済み */
                async function fetchShared(t0, t1) {
                    const q = new URLSearchParams({ phase: card.dataset.pid, t0: String(t0), t1: String(t1) });
                    const key = 'shared:' + q.toString();
                    if (rangeCache.has(key)) return rangeCache.get(key);
                    const r = await fetch(SHARE_RANGE_URL + '?' + q.toString(), { headers: { 'Accept': 'application/json' } });
                    const j = r.ok ? await r.json() : await r.json().catch(() => ({})).then(e => ({ error: e.error || ('HTTP ' + r.status) }));
                    if (!j.error) rangeCache.set(key, j);
                    return j;
                }

                /** 選択範囲をFFLogsで再集計し、パーティ計を確定値に差し替えたうえで
                 *  フェーズカードと同じ3ブロック（不足側アドバイス／ロールペア別／個人別バー）を描き直す。
                 *  個人別rDPSはシナジー配分（rDPSの本体）が絡むため生ダメージからは復元できず、サーバ経由が必須。 */
                async function loadDetail(t0, t1) {
                    const seq = ++reqSeq;
                    const box = panel.querySelector('.ts-detail');
                    if (!box) return;
                    if (!CAN_REFETCH) {
                        box.innerHTML = '<span class="text-warning text-base">この共有は範囲の再集計に対応する前に作られたため、範囲の個人別rDPSは出せません（パーティ計は概算値です）。作成者が共有を作り直すと対応します。</span>';
                        return;
                    }
                    box.innerHTML = '<span class="text-secondary text-base">' + (RANGE_MODE === 'shared' ? '再集計中…' : 'FFLogsで再集計中…') + '</span>';

                    // A/Bでフェーズ長が違うと、片方だけ選択範囲がフェーズ末尾をはみ出す。
                    // はみ出したぶんを黙って詰めると「20秒 vs 6秒」を並べることになり
                    // rDPSの意味が変わるので、側ごとに実範囲へ切り詰めた上で食い違いを明示する。
                    const win = {};
                    ['a', 'b'].forEach(k => {
                        const s = SRC[k];
                        if (!s) return;
                        const end = Math.min(t1, Math.max(0, (s.end - s.start) / 1000));
                        if (end - t0 >= 2) win[k] = { t0: t0, t1: end };
                    });
                    if (!Object.keys(win).length) {
                        box.innerHTML = '<span class="text-secondary text-base">選択範囲がどちらのフェーズにも掛かっていません。</span>';
                        return;
                    }

                    let res;
                    try {
                        res = RANGE_MODE === 'shared' ? await fetchShared(t0, t1) : await fetchRange(win);
                    } catch (e) {
                        res = { error: e.message };
                    }
                    if (seq !== reqSeq || !chart.$sel) return; // ドラッグし直された・解除された：古い応答は捨てる
                    if (!res || res.error) {
                        box.innerHTML = '<span class="text-red text-base">再集計に失敗しました（' + esc((res && res.error) || '不明') + '）</span>' +
                            ' <button type="button" class="btn ts-btn" data-act="retry">再取得</button>';
                        bindRetry(box, t0, t1);
                        return;
                    }

                    // パーティ計をFFLogs基準の確定値に差し替え
                    const vals = panel.querySelector('.ts-vals');
                    if (vals) vals.innerHTML = valsHtml(res.a && res.a.partyRdps, res.b && res.b.partyRdps, false, res);

                    // フェーズ長の差で範囲がズレたときの警告（黙って違う長さを比較させない）。
                    // 共有では10秒単位に丸めた実際の範囲がサーバーから返る
                    const winOf = x => (x && x.win ? { t0: x.win[0], t1: x.win[1] } : null);
                    const wa = RANGE_MODE === 'shared' ? winOf(res.a) : win.a;
                    const wb = RANGE_MODE === 'shared' ? winOf(res.b) : win.b;
                    const shown = wa || wb || { t0: t0, t1: t1 };
                    const wlen = w => Math.round(w.t1 - w.t0);
                    let html = '';
                    if (wa && wb && Math.abs(wlen(wa) - wlen(wb)) >= 1) {
                        html += '<div class="ts-warn">⚠ A/Bでフェーズ長が違うため、選択範囲を各ログの末尾で切り詰めました：' +
                            '<span class="text-blue">A ' + fmtT(wa.t0) + '–' + fmtT(wa.t1) + '（' + wlen(wa) + 's）</span> ／ ' +
                            '<span class="text-red">B ' + fmtT(wb.t0) + '–' + fmtT(wb.t1) + '（' + wlen(wb) + 's）</span>。' +
                            'rDPSは各ログの実範囲での値なので、長さが揃うまで範囲を狭めた方が比較になります。</div>';
                    } else if (SRC.a && SRC.b && (!wa || !wb)) {
                        html += '<div class="ts-warn">⚠ <span class="' + (wa ? 'text-red' : 'text-blue') + '">' +
                            (wa ? 'B' : 'A') + '</span> はこのフェーズが短く、選択範囲に掛かっていません（片側のみの値です）。</div>';
                    }

                    // 3ブロック（不足側アドバイス／ロールペア別／個人別ミラーバー）は
                    // フェーズカードと同じ Blade パーシャルをサーバ側で描画したもの。JSでは組み立てず、カードの元の欄と入れ替える。
                    applyCard(res, fmtT(shown.t0) + '–' + fmtT(shown.t1));
                    if (RANGE_MODE === 'shared') {
                        html += '<div class="ts-note">共有では、作成時に取り置いた10秒ごとの集計から出しています（範囲は ' + fmtT(shown.t0) + '–' + fmtT(shown.t1) +
                            ' に丸めました）。rDPS・総ダメージはFFLogsと同じ値、Active%（稼働率）は推定です。</div>';
                    }
                    html += '<div class="ts-note text-yellow">↓ このフェーズのカード（パーティ計・不足側の分析・ロールペア別・個人別）を選択範囲の値に置き換えました。「解除」かダブルクリックで元に戻ります。</div>';

                    const secs = [res.a, res.b].filter(Boolean).map(x => nf(x.combatSec) + 's').join(' / ');
                    html += '<div class="ts-note">rDPSの分母はFFLogsと同じ「ボスが攻撃可能だった実時間」（この範囲では ' + esc(secs) +
                        '）。壁時計の ' + Math.round(shown.t1 - shown.t0) + 's とは一致しません。薬の推定上乗せDPSは範囲集計では出していません。</div>';
                    box.innerHTML = html;
                }

                function bindRetry(box, t0, t1) {
                    const b = box.querySelector('[data-act="retry"]');
                    if (b) b.addEventListener('click', () => loadDetail(t0, t1));
                }
            });
        })();
    </script>

    <div class="legacy-flex legacy-justify-center legacy-pb-8">@include('_copyright')</div>
@if (($shareDraft ?? false) || ($isShared ?? false))
    @include('fflogs._timeline_share', [
        'shareTitle' => 'DPS比較の結果をURLで共有',
        'shareNotice' => ($hideNames ?? false)
            ? 'プレイヤー名は「SCH(A)」のような表記で保存され、実名は共有されません。FFLogsのレポート番号も共有されません。'
            : 'URLを知っている人は、プレイヤー名を含む比較結果を閲覧できます。FFLogsのレポート番号とAPIキーは共有されません。',
    ])
@endif
</body>

</html>
