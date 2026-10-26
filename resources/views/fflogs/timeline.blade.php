<!DOCTYPE html>
<html lang="ja" data-bs-theme="dark">

<head>
    @if ($isShared ?? false)<meta name="robots" content="noindex,nofollow,noarchive"><meta name="referrer" content="no-referrer">@endif
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timeline - {{ $fight['name'] }}</title>
    @include('_ui_head')
    <link href="{{ asset('css/timeline.css') }}?v={{ @filemtime(public_path('css/timeline.css')) }}" rel="stylesheet">
</head>

<body class="logs-app timeline-app">
<script>
                        // Pass server-side data to global scope for external JS
                        window.playerTimelines = @json($playerTimelines);
                        window.playerDetails = @json($playerDetails);
                        window.enemyEvents = @json($events);
                        window.mitCols = @json($mitigationColumns);
                        window.fightMetadata = @json($fight);
                        window.IMAGE_PROXY_BASE = @json(route('proxy_image'));
                        window.ICON_BASE = @json(asset('icons'));
                    </script>
<script src="https://cdn.jsdelivr.net/npm/modern-screenshot@4.7.0/dist/index.js"></script>
<script src="{{ asset('js/timeline.js') }}?v={{ filemtime(public_path('js/timeline.js')) }}"></script>

    @include('fflogs._timeline_header')
    <!-- ===== 軽減・バリア ビュー ===== -->
    <div id="view-mitigation" tabindex="0" role="tabpanel" aria-labelledby="tab-btn-mitigation" class="timeline-view legacy-flex-1 legacy-w-full overflow-auto bg-body legacy-relative">
        <table id="mitigation-table" class="legacy-w-full text-start legacy-border-separate legacy-min-w-max {{ $hasPhases ? 'has-phase' : '' }}">
            <thead class="bg-surface text-secondary">
                <!-- Row 1: Groups & Main Headers -->
                <tr class="legacy-h-10">
                    @if ($hasPhases)
                        <th rowspan="2" style="position: sticky; top: 0; left: 0; z-index: 66;"
                            class="legacy-p-1 legacy-w-12 bg-surface align-middle shadow-sm text-center text-base">
                            Phase</th>
                    @endif
                    <th rowspan="2" style="position: sticky; top: 0; left: {{ $hasPhases ? '3rem' : '0' }}; z-index: 65;"
                        class="legacy-p-1 legacy-w-16 bg-surface align-middle shadow-sm">
                        Time</th>
                    <th rowspan="2" style="position: sticky; top: 0; left: {{ $hasPhases ? '7rem' : '4rem' }}; z-index: 65;"
                        class="legacy-p-1 bg-surface align-middle legacy-w-48 shadow-sm">
                        Action</th>
                    <th rowspan="2" style="position: sticky; top: 0; left: {{ $hasPhases ? '15rem' : '12rem' }}; z-index: 65;"
                        class="legacy-p-1 text-end bg-surface align-middle legacy-w-16 shadow-sm text-base"
                        title="素のダメージ＝軽減・バリアが一切無かった場合のダメージ（グループ内の最大値）。バリア吸収分もここに含まれるため、Base Dmg − Hit Dmg は軽減量とは一致しない">
                        Base<br>Dmg</th>
                    <th rowspan="2" style="position: sticky; top: 0; left: {{ $hasPhases ? '19rem' : '16rem' }}; z-index: 65;"
                        class="legacy-p-1 text-end bg-surface align-middle legacy-w-16 text-base sticky-divider shadow-sm"
                        title="実被弾＝バリアを抜けてHPに向かった量（オーバーキル込み・グループ内の最大値）。(-xx%) は軽減率（素のダメージ→軽減後ダメージ。バリア吸収は含まない）。クリックで内訳">
                        Hit<br>Dmg</th>

                    <!-- Dynamic Player Headers (Rowspanned) -->
                    @foreach ($playerColumns as $player)
                        @php $pDetail = $playerDetails[$player] ?? ['icon' => null, 'name' => $player]; @endphp
                        <th rowspan="2" data-player="{{ $player }}" title="{{ $pDetail['name'] }}" onclick="openPlayerTimeline({{ \Illuminate\Support\Js::from($player) }})"
                            class="legacy-p-1 border border-secondary bg-body-tertiary text-white legacy-sticky legacy-w-[50px] legacy-min-w-[50px] player-col"
                            style="top: 0; z-index: 60; background-color: var(--tblr-bg-surface-secondary);">
                            <div class="legacy-flex legacy-flex-col legacy-items-center legacy-justify-center legacy-gap-1">
                                @if ($pDetail['icon'])
                                    <img src="{{ $pDetail['icon'] }}" loading="lazy"
                                        class="player-job-icon legacy-object-contain rounded" onerror="this.style.display='none'">
                                @endif
                                <div class="legacy-truncate legacy-w-full legacy-p-1 text-center text-base text-secondary">
                                    {{ substr($pDetail['name'], 0, 5) }}</div>
                            </div>
                        </th>
                    @endforeach

                    <!-- Mitigation Groups -->
                    @foreach ($mitigationGroups as $groupName => $cols)
                        <th title="{{ $groupName }}" class="mitigation-group {{ count($cols) <= 2 ? 'mitigation-group-narrow' : '' }} legacy-p-1 border border-secondary bg-surface text-white fw-bold legacy-sticky legacy-top-0 legacy-z-[60] legacy-cursor-pointer  group"
                            style="top: 0; z-index: 60; background-color: var(--tblr-bg-surface-secondary);"
                            colspan="{{ count($cols) }}" onclick="openJobTimeline({{ \Illuminate\Support\Js::from($groupName) }})">
                            <div class="mitigation-group-content">
                                @if (isset($mitigationGroupIcons[$groupName]))
                                    <img src="{{ $mitigationGroupIcons[$groupName] }}" class="group-job-icon" loading="lazy">
                                @endif
                                <span
                                    class="mitigation-group-label text-base">{{ $groupName }}</span>
                            </div>
                        </th>
                    @endforeach
                </tr>
                <!-- Row 2: Mitigation Icons -->
                <tr class="legacy-h-10">
                    @foreach ($mitigationGroups as $groupName => $cols)
                        @foreach ($cols as $key => $col)
                            <th style="position: sticky; top: 2.5rem; z-index: 40;"
                                class="legacy-py-1 legacy-px-0 legacy-w-8 text-center bg-surface legacy-h-10 align-middle group legacy-relative">
                                @php $skillName = $col['name'] ?? $key; @endphp
                                <div class="legacy-flex legacy-flex-col legacy-items-center legacy-justify-center">
                                    @if ($col['icon'])
                                        <img src="{{ asset('icons') }}/abilities/{{ $col['icon'] }}"
                                            class="ability-header-icon legacy-object-contain" alt="{{ $skillName }}" loading="lazy"
                                            onerror="this.style.display='none'; this.nextElementSibling.style.display='block'"
                                            title="{{ $skillName }}">
                                        <span class="text-base hidden">{{ $skillName }}</span>
                                    @else
                                        <span class="text-base">{{ $skillName }}</span>
                                    @endif
                                </div>
                            </th>
                        @endforeach
                    @endforeach
                </tr>
            </thead>
            <tbody class="text-base  ">
                @php $prevPhase = null; @endphp
                @foreach ($events as $event)
                    @php $curPhase = $event['phase'] ?? null; @endphp
                    <tr class="  {{ $event['is_dot'] ? 'row-dot' : '' }}">

                        @if ($hasPhases)
                            <td class="c-phase phase-p{{ $curPhase % 6 }} legacy-p-1 text-center fw-bold bg-body"
                                title="Phase {{ $curPhase }}">
                                @if ($curPhase !== $prevPhase)P{{ $curPhase }}@endif
                            </td>
                            @php $prevPhase = $curPhase; @endphp
                        @endif
                        <td class="c-time legacy-p-1 font-monospace text-secondary bg-body legacy-w-16">
                            {{ gmdate('i:s', $event['rel_time']) }}
                        </td>
                        <td class="c-action legacy-p-1 fw-bold text-white bg-body ability-name legacy-truncate legacy-w-48 legacy-max-w-[12rem] legacy-cursor-pointer   "
                            onclick="openActionDetail({{ \Illuminate\Support\Js::from($event['ability']) }}, {{ $event['timestamp'] }})">
                            {{ $event['ability'] }}
                        </td>
                        <td class="c-base legacy-p-1 text-end text-secondary font-monospace bg-body text-base legacy-w-16">
                            <div>{{ number_format($event['max_unmitigated'] ?? 0) }}</div>
                        </td>
                        @php $hasHit = ($event['max_amount'] ?? 0) > 0; @endphp
                        <td class="c-hit legacy-p-1 text-end text-orange font-monospace fw-bold bg-body legacy-px-2 text-base legacy-w-16 sticky-divider shadow-sm {{ $hasHit ? 'legacy-cursor-pointer   ' : '' }}"
                            @if ($hasHit) onclick="openDamageDetail({{ \Illuminate\Support\Js::from($event['ability']) }}, {{ $event['timestamp'] }})" title="クリックで被弾内訳（軽減スキル・バリア・実被弾）を表示" @endif>
                            <div class="hit-damage-amount">{{ number_format($event['max_amount'] ?? 0) }}</div>
                            @php
                                $hitRate = $event['mitigation_rate'] ?? 0;
                                $barrierAll = $event['absorbed_total'] ?? 0;
                                $barrierMax = $event['max_absorbed'] ?? 0;
                                // インライン要素どうしの間にBladeのインデントが空白テキストとして
                                // 入ってしまうため、断片を組み立ててから隙間なしで出力する
                                $hitBadges = [];
                                if ($hitRate > 0) {
                                    $hitBadges[] = '<span class="text-green">(-' . (int) $hitRate . '%)</span>';
                                }
                                if ($barrierAll > 0) {
                                    $barrierTip = 'バリアが吸収 ' . number_format($barrierMax)
                                        . '（最大被弾者）／グループ合計 ' . number_format($barrierAll)
                                        . '。実被弾には含まれない';
                                    $hitBadges[] = '<span class="text-cyan fw-bold text-base" title="'
                                        . e($barrierTip) . '">+B</span>';
                                }
                            @endphp
                            @if ($hitBadges)
                                <span class="hit-damage-details text-base fw-normal text-nowrap">{!! implode('', $hitBadges) !!}</span>
                            @endif
                        </td>

                        <!-- Player Cells -->
                        @foreach ($playerColumns as $player)
                            <td class="legacy-p-1 text-center text-base player-col"
                                data-player="{{ $player }}">
                                @if (isset($event['players'][$player]))
                                    @php
                                        $pData = $event['players'][$player];
                                        // amount は実被弾（HPに向かった量＝HP減+オーバーキル）。バリア吸収は含まない
                                        $pAmt = $pData['amount'];
                                        $pUnmit = $pData['unmitigated'] ?? $pAmt;
                                        $pAbsorbed = $pData['absorbed'] ?? 0;
                                        $pOverkill = $pData['overkill'] ?? 0;
                                        $pHpLost = $pData['hp_lost'] ?? $pAmt;
                                        $pMitRate = 0;
                                        if ($pUnmit > 0 && $pUnmit > $pAmt) {
                                            $pMitRate = round((($pUnmit - $pAmt) / $pUnmit) * 100);
                                        }
                                        $pTip = '生ダメージ ' . number_format($pUnmit)
                                            . ' → 実被弾 ' . number_format($pAmt) . '（-' . $pMitRate . '%）';
                                        if ($pAbsorbed > 0) {
                                            $pTip .= "\nバリア吸収 " . number_format($pAbsorbed) . '（実被弾には含まない）';
                                        }
                                        if ($pOverkill > 0) {
                                            $pTip .= "\nうちHP減 " . number_format($pHpLost)
                                                . ' / オーバーキル ' . number_format($pOverkill);
                                        }
                                    @endphp
                                    <div class="legacy-flex legacy-flex-col legacy-items-center" title="{{ $pTip }}">
                                        <span class="text-red">
                                            {{ number_format($pAmt) }}
                                        </span>
                                        @if ($pMitRate > 0)
                                            <span class="text-green text-base">-{{ $pMitRate }}%</span>
                                        @endif
                                        @if ($pOverkill > 0)
                                            <span class="text-red text-base" title="この一撃で死亡">☠</span>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-secondary">-</span>
                                @endif
                            </td>
                        @endforeach

                        <!-- Mitigation Matrix（チェックボックス風UI・CSS擬似要素で描画） -->
                        @foreach ($mitigationColumns as $key => $col)
                            @php
                                $cellData = $event['cols'][$key] ?? ['active' => false, 'sources' => []];
                                $isActive = $cellData['active'];
                                $broken = $isActive && ($cellData['is_broken'] ?? false);
                                $isUsed = $cellData['used'] ?? false;
                                $isUp = !$isActive && ($cellData['up'] ?? false);
                                $onCd = !$isActive && !$isUp && ($cellData['cooldown'] ?? false);
                                // 優先順位：使用マーカー > 被弾軽減(チェック) > 効果中 > リキャスト中 > 未使用
                                $mitClass = $isUsed
                                    ? 'mit-used'
                                    : ($isActive
                                        ? ($broken ? 'mit-x' : 'mit-on')
                                        : ($isUp ? 'mit-up' : ($onCd ? 'mit-cd' : 'mit-off')));
                                $sourceText = implode(', ', $cellData['sources'] ?? []);
                                $skillName = $col['name'] ?? $key;
                                $cellTitle = $skillName;
                                if ($isUsed) {
                                    $cellTitle .= ' 使用 ' . gmdate('i:s', (int)($cellData['used_time'] ?? 0))
                                        . (($cellData['used_src'] ?? '') ? '（' . $cellData['used_src'] . '）' : '');
                                } elseif ($isActive) {
                                    $cellTitle .= ' 発動中' . ($sourceText ? '：' . $sourceText : '');
                                } elseif ($isUp) {
                                    $cellTitle .= ' 効果中';
                                } elseif ($onCd) {
                                    $cellTitle .= ' リキャスト中';
                                }
                            @endphp
                            <td class="mit {{ $mitClass }}" title="{{ $cellTitle }}"></td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if (count($events) === 0)
            <div class="legacy-p-8 text-center text-secondary">
                No events found.
            </div>
        @endif
    </div>

    <!-- ===== シナジー・バフ ビュー（既定は非表示） ===== -->
    <div id="view-synergy" tabindex="0" role="tabpanel" aria-labelledby="tab-btn-synergy" class="timeline-view legacy-flex-1 legacy-w-full overflow-auto bg-body legacy-relative hidden">
        @if (count($synergyWindows) === 0)
            <div class="legacy-p-8 text-center text-secondary" style="font-size:1.05rem;">シナジーバフの使用が見つかりませんでした。</div>
        @else
            <div class="legacy-p-3 legacy-space-y-4">
                @foreach ($synergyWindows as $win)
                    <div class="syn-window rounded border border-secondary bg-surface overflow-hidden">
                        <div class="syn-window-head legacy-flex legacy-items-center legacy-gap-3 legacy-px-4 legacy-py-2">
                            <span class="text-yellow fw-bold" style="font-size:1.15rem;">🔥 バースト窓 #{{ $win['index'] + 1 }}</span>
                            <span class="text-body font-monospace" style="font-size:1.05rem;">{{ gmdate('i:s', (int) $win['start']) }}〜{{ gmdate('i:s', (int) $win['end']) }}</span>
                            <span class="legacy-ml-auto text-secondary" style="font-size:1rem;">{{ $win['players_count'] }}人 / {{ $win['total'] }}件</span>
                        </div>
                        <div class=" ">
                            {{-- 人単位で1行：その人が窓内で使ったシナジースキルを横並び --}}
                            @foreach ($win['players'] as $p)
                                <div class="syn-row legacy-flex legacy-items-start legacy-gap-3 legacy-px-4 legacy-py-2 ">
                                    <span class="font-monospace text-secondary legacy-w-12 text-end legacy-shrink-0" style="font-size:1rem; padding-top:0.2rem;">{{ gmdate('i:s', (int) $p['first_time']) }}</span>
                                    {{-- 使用者（名前は折り返さず1行） --}}
                                    <span class="legacy-flex legacy-items-center legacy-gap-2 legacy-shrink-0 text-nowrap" style="min-width:13rem;">
                                        @if ($p['player_icon'])
                                            <img src="{{ $p['player_icon'] }}" class="legacy-w-7 legacy-h-7 legacy-object-contain rounded legacy-shrink-0" loading="lazy"
                                                onerror="this.style.display='none'">
                                        @endif
                                        <span class="text-white fw-bold text-nowrap" style="font-size:1.05rem;">{{ $p['player'] }}</span>
                                        <span class="text-secondary legacy-shrink-0" style="font-size:1rem;">{{ $p['job'] }}</span>
                                    </span>
                                    {{-- 使ったスキルをチップで横並び --}}
                                    <div class="legacy-flex legacy-flex-wrap legacy-items-center legacy-gap-2">
                                        @foreach ($p['skills'] as $sk)
                                            @php
                                                if ($sk['is_item']) { $chipClass = 'chip-item'; }
                                                elseif ($sk['cd'] == 120) { $chipClass = 'chip-cd120'; }
                                                elseif ($sk['cd'] == 60) { $chipClass = 'chip-cd60'; }
                                                else { $chipClass = 'chip-other'; }
                                            @endphp
                                            <span class="syn-chip {{ $chipClass }}">
                                                @if ($sk['skill_icon'])
                                                    <img src="{{ asset('icons') }}/abilities/{{ $sk['skill_icon'] }}" class="legacy-w-5 legacy-h-5 legacy-object-contain" loading="lazy"
                                                        alt="{{ $sk['skill'] }}" onerror="this.style.display='none'">
                                                @elseif ($sk['is_item'])
                                                    <span>💊</span>
                                                @endif
                                                <span>{{ $sk['skill'] }}</span>
                                                @unless ($sk['self_target'])
                                                    <span class="legacy-opacity-70" style="font-size:1rem;">▸{{ $sk['target'] }}</span>
                                                @endunless
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <!-- ===== デスチェッカー ビュー（既定は非表示） ===== -->
    <div id="view-death" tabindex="0" role="tabpanel" aria-labelledby="tab-btn-death" class="timeline-view legacy-flex-1 legacy-w-full overflow-auto bg-body legacy-relative hidden">
        @if (count($deaths) === 0)
            <div class="legacy-p-8 text-center text-secondary" style="font-size:1.05rem;">この戦闘では死亡者がいませんでした。🎉</div>
        @else
            <div class="legacy-p-3 legacy-space-y-4">
                @foreach ($deaths as $g)
                    <div class="death-card rounded border border-secondary bg-surface overflow-hidden">
                        {{-- ヘッダー：時刻帯・とどめスキル・死亡者まとめ --}}
                        <div class="death-head legacy-flex legacy-flex-wrap legacy-items-center legacy-gap-x-3 legacy-gap-y-1 legacy-px-4 legacy-py-2 bg-surface">
                            <span class="text-red fw-bold" style="font-size:1.15rem;">💀 #{{ $g['index'] + 1 }}</span>
                            <span class="text-body font-monospace" style="font-size:1.05rem;">
                                {{ $g['time_start'] }}@if ($g['multi_time'])〜{{ $g['time_end'] }}@endif
                            </span>
                            <span class="legacy-flex legacy-items-center legacy-gap-2 text-yellow" style="font-size:1.05rem;">
                                <span class="text-secondary" style="font-size:1.0rem;">死因:</span>
                                @if ($g['kill_ability']['icon'])
                                    <img src="{{ asset('icons') }}/abilities/{{ $g['kill_ability']['icon'] }}" class="legacy-w-6 legacy-h-6 legacy-object-contain" loading="lazy"
                                        onerror="this.style.display='none'">
                                @endif
                                <span class="fw-bold">{{ $g['kill_ability']['name'] }}</span>
                            </span>
                            @if (count($g['raidwide_names']))
                                <span class="legacy-inline-flex legacy-items-center legacy-px-2 legacy-py-0.5 rounded bg-purple-lt border border-purple text-purple fw-bold" style="font-size:1rem;" title="アリーナ全体に及ぶ攻撃（回避不可）。{{ implode('・', $g['raidwide_names']) }}">
                                    🌐 全体攻撃
                                </span>
                            @endif
                            <span class="legacy-ml-auto legacy-inline-flex legacy-items-center legacy-px-2 legacy-py-0.5 rounded-pill bg-red-lt border border-red text-red fw-bold" style="font-size:1rem;">
                                {{ $g['victim_count'] }}人 死亡
                            </span>
                            {{-- 死亡者チップ（まとめ） --}}
                            <div class="legacy-w-full legacy-flex legacy-flex-wrap legacy-items-center legacy-gap-1.5 legacy-mt-1">
                                @foreach ($g['victims'] as $v)
                                    <span class="legacy-inline-flex legacy-items-center legacy-gap-1 legacy-px-2 legacy-py-0.5 rounded bg-body border border-secondary" style="font-size:1.0rem;">
                                        <span style="font-size:1.0rem;">💀</span>
                                        @if ($v['victim']['icon'])<img src="{{ $v['victim']['icon'] }}" class="legacy-w-4 legacy-h-4" loading="lazy" onerror="this.style.display='none'">@endif
                                        <span class="text-white fw-bold">{{ $v['victim']['name'] }}</span>
                                        <span class="text-secondary" style="font-size:1.0rem;">{{ $v['victim']['job'] }}</span>
                                        <span class="text-secondary font-monospace" style="font-size:1.0rem;">{{ $v['rel_time'] }}</span>
                                    </span>
                                @endforeach
                            </div>
                        </div>

                        <div class="legacy-flex legacy-flex-col legacy-lg:flex-row legacy-gap-4 legacy-p-4">
                            {{-- 左：円形マップ --}}
                            <div class="legacy-shrink-0 legacy-flex legacy-flex-col legacy-items-center legacy-gap-2">
                                <svg viewBox="0 0 200 200" width="320" height="320" class="death-map rounded-pill border border-secondary bg-body">
                                    {{-- フィールド円 --}}
                                    <circle cx="100" cy="100" r="95" fill="#0b0f1a" stroke="#374151" stroke-width="1" />
                                    <circle cx="100" cy="100" r="62" fill="none" stroke="#1f2937" stroke-width="0.6" />
                                    <line x1="100" y1="5" x2="100" y2="195" stroke="#1f2937" stroke-width="0.5" />
                                    <line x1="5" y1="100" x2="195" y2="100" stroke="#1f2937" stroke-width="0.5" />
                                    {{-- AoE攻撃範囲。死因スキル=塗りつぶし＋太線で強調、その他=輪郭のみ細線。赤=被弾/黄=回避・塔 --}}
                                    {{-- AoEは薄く塗りつぶし＋破線輪郭。黄=テレグラフ(塔/回避)/赤=被弾。扇/円/頭割り共通 --}}
                                    @foreach ($g['aoe'] as $a)
                                        @php $aoeC = $a['tele'] ? '#eab308' : '#ef4444'; @endphp
                                        @if ($a['type'] === 'circle')
                                            <circle cx="{{ $a['cx'] }}" cy="{{ $a['cy'] }}" r="{{ $a['r'] }}" fill="{{ $aoeC }}" fill-opacity="0.12" stroke="{{ $aoeC }}" stroke-width="0.8" stroke-dasharray="2 1.5" />
                                        @elseif ($a['type'] === 'donut')
                                            <circle cx="{{ $a['cx'] }}" cy="{{ $a['cy'] }}" r="{{ $a['r_out'] }}" fill="{{ $aoeC }}" fill-opacity="0.12" stroke="{{ $aoeC }}" stroke-width="0.8" stroke-dasharray="2 1.5" />
                                            @if ($a['r_in'] > 0)
                                                <circle cx="{{ $a['cx'] }}" cy="{{ $a['cy'] }}" r="{{ $a['r_in'] }}" fill="#0b0f1a" stroke="{{ $aoeC }}" stroke-width="0.6" stroke-dasharray="2 1.5" />
                                            @endif
                                        @elseif ($a['type'] === 'poly')
                                            <polygon points="{{ $a['points'] }}" fill="{{ $aoeC }}" fill-opacity="0.12" stroke="{{ $aoeC }}" stroke-width="0.8" stroke-dasharray="2 1.5" />
                                        @endif
                                    @endforeach
                                    {{-- ボス --}}
                                    @if ($g['boss_dot'])
                                        <rect x="{{ $g['boss_dot']['sx'] - 4 }}" y="{{ $g['boss_dot']['sy'] - 4 }}" width="8" height="8"
                                            fill="#b91c1c" stroke="#fca5a5" stroke-width="0.8" transform="rotate(45 {{ $g['boss_dot']['sx'] }} {{ $g['boss_dot']['sy'] }})" />
                                    @endif
                                    {{-- プレイヤー（💀=この時に死亡 / ❌=既に死亡 / それ以外=生存） --}}
                                    @foreach ($g['dots'] as $dot)
                                        @php
                                            $dotColor = $dot['is_already_dead'] ? '#6b7280' : ($dot['is_dead'] ? '#ef4444' : ($dot['role'] === 'tank' ? '#3b82f6' : ($dot['role'] === 'healer' ? '#22c55e' : '#eab308')));
                                        @endphp
                                        @if ($dot['is_already_dead'])
                                            {{-- 既に死亡：灰色＋❌、薄く --}}
                                            <g opacity="0.5">
                                                <circle cx="{{ $dot['sx'] }}" cy="{{ $dot['sy'] }}" r="4.2" fill="{{ $dotColor }}" stroke="#0b0f1a" stroke-width="0.8" />
                                                <text x="{{ $dot['sx'] }}" y="{{ $dot['sy'] }}" text-anchor="middle" dominant-baseline="central" style="font-size:7px;" class="legacy-select-none">❌</text>
                                                <text x="{{ $dot['sx'] }}" y="{{ $dot['sy'] - 6 }}" text-anchor="middle" fill="#9ca3af" style="font-size:6px;" class="legacy-select-none">{{ mb_substr($dot['name'], 0, 4) }}</text>
                                            </g>
                                        @else
                                            @if ($dot['is_dead'])
                                                <circle cx="{{ $dot['sx'] }}" cy="{{ $dot['sy'] }}" r="8" fill="none" stroke="#ef4444" stroke-width="1" opacity="0.8" />
                                            @elseif ($dot['is_hit'])
                                                <circle cx="{{ $dot['sx'] }}" cy="{{ $dot['sy'] }}" r="7" fill="none" stroke="#f97316" stroke-width="1" stroke-dasharray="2 1.5" opacity="0.8" />
                                            @endif
                                            <circle cx="{{ $dot['sx'] }}" cy="{{ $dot['sy'] }}" r="4.2" fill="{{ $dotColor }}" stroke="#0b0f1a" stroke-width="0.8" />
                                            @if ($dot['is_dead'])
                                                <text x="{{ $dot['sx'] }}" y="{{ $dot['sy'] }}" text-anchor="middle" dominant-baseline="central" style="font-size:7px;" class="legacy-select-none">💀</text>
                                            @endif
                                            <text x="{{ $dot['sx'] }}" y="{{ $dot['sy'] - 6 }}" text-anchor="middle" fill="{{ $dot['is_dead'] ? '#fca5a5' : '#e5e7eb' }}" style="font-size:6px;" class="legacy-select-none">{{ mb_substr($dot['name'], 0, 4) }}</text>
                                        @endif
                                    @endforeach
                                </svg>
                                <div class="legacy-flex legacy-flex-wrap legacy-items-center legacy-justify-center legacy-gap-x-3 legacy-gap-y-1 text-secondary" style="font-size:1.0rem;">
                                    <span><span style="color:#3b82f6;">●</span>Tank</span>
                                    <span><span style="color:#22c55e;">●</span>Healer</span>
                                    <span><span style="color:#eab308;">●</span>DPS</span>
                                    <span>💀死亡</span>
                                    <span>❌既に死亡</span>
                                    <span><span style="color:#f97316;">◌</span>被弾</span>
                                    <span><span style="color:#b91c1c;">◆</span>敵</span>
                                    @if (count($g['aoe']))<span><span style="color:#ef4444;">▱</span>攻撃範囲</span><span><span style="color:#eab308;">▱</span>塔/回避テレグラフ</span>@endif
                                </div>
                            </div>

                            {{-- 右：死因パネル --}}
                            <div class="legacy-flex-1 legacy-min-w-0 legacy-space-y-3">
                                {{-- 被弾比較（このグループのとどめスキル） --}}
                                <div class="rounded border border-secondary bg-body legacy-p-3">
                                    <div class="text-secondary legacy-mb-2" style="font-size:1.0rem;">「{{ $g['kill_ability']['name'] }}」の被弾比較</div>
                                    <div class="legacy-flex legacy-flex-col legacy-sm:flex-row legacy-gap-3">
                                        <div class="legacy-flex-1">
                                            <div class="text-red legacy-mb-1" style="font-size:1.0rem;">被弾した人 ({{ count($g['hit_players']) }})</div>
                                            <div class="legacy-flex legacy-flex-wrap legacy-gap-1.5">
                                                @forelse ($g['hit_players'] as $p)
                                                    <span class="legacy-inline-flex legacy-items-center legacy-gap-1 legacy-px-2 legacy-py-0.5 rounded bg-red-lt border border-red" style="font-size:1.0rem;">
                                                        @if ($p['icon'])<img src="{{ $p['icon'] }}" class="legacy-w-4 legacy-h-4" loading="lazy" onerror="this.style.display='none'">@endif
                                                        <span class="text-body">{{ $p['name'] }}</span>
                                                    </span>
                                                @empty
                                                    <span class="text-secondary" style="font-size:1.0rem;">—</span>
                                                @endforelse
                                            </div>
                                        </div>
                                        <div class="legacy-flex-1">
                                            <div class="text-green legacy-mb-1" style="font-size:1.0rem;">回避した人 ({{ count($g['safe_players']) }})</div>
                                            <div class="legacy-flex legacy-flex-wrap legacy-gap-1.5">
                                                @forelse ($g['safe_players'] as $p)
                                                    <span class="legacy-inline-flex legacy-items-center legacy-gap-1 legacy-px-2 legacy-py-0.5 rounded bg-green-lt border border-green" style="font-size:1.0rem;">
                                                        @if ($p['icon'])<img src="{{ $p['icon'] }}" class="legacy-w-4 legacy-h-4" loading="lazy" onerror="this.style.display='none'">@endif
                                                        <span class="text-body">{{ $p['name'] }}</span>
                                                    </span>
                                                @empty
                                                    <span class="text-secondary" style="font-size:1.0rem;">—</span>
                                                @endforelse
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- 死亡者ごとの詳細（とどめ・致死前HP・直前被弾） --}}
                                @foreach ($g['victims'] as $v)
                                    <div class="rounded border border-secondary bg-body legacy-p-3">
                                        <div class="legacy-flex legacy-items-center legacy-gap-2 legacy-mb-2">
                                            <span style="font-size:1.0rem;">💀</span>
                                            @if ($v['victim']['icon'])<img src="{{ $v['victim']['icon'] }}" class="legacy-w-5 legacy-h-5" loading="lazy" onerror="this.style.display='none'">@endif
                                            <span class="text-white fw-bold" style="font-size:1.05rem;">{{ $v['victim']['name'] }}</span>
                                            <span class="text-secondary" style="font-size:1.0rem;">{{ $v['victim']['job'] }}</span>
                                            <span class="text-secondary font-monospace legacy-ml-auto" style="font-size:1.0rem;">{{ $v['rel_time'] }}</span>
                                        </div>

                                        {{-- 致死前HP バー --}}
                                        @if ($v['hp_before'] !== null && $v['maxhp'])
                                            @php $hpPct = max(0, min(100, round($v['hp_before'] / max(1, $v['maxhp']) * 100))); @endphp
                                            <div class="legacy-mb-2">
                                                <div class="legacy-flex legacy-justify-between text-secondary legacy-mb-0.5" style="font-size:1.0rem;">
                                                    <span>致死前HP</span>
                                                    <span class="font-monospace">{{ number_format($v['hp_before']) }} / {{ number_format($v['maxhp']) }}（{{ $hpPct }}%）</span>
                                                </div>
                                                <div class="legacy-w-full legacy-h-2.5 rounded bg-body-tertiary overflow-hidden">
                                                    <div class="legacy-h-full rounded {{ $hpPct <= 30 ? 'bg-red' : ($hpPct <= 60 ? 'bg-yellow' : 'bg-green') }}" style="width: {{ $hpPct }}%;"></div>
                                                </div>
                                            </div>
                                        @endif

                                        {{-- とどめの一撃 --}}
                                        @if ($v['lethal'])
                                            <div class="legacy-flex legacy-flex-wrap legacy-items-center legacy-gap-x-4 legacy-gap-y-1 legacy-mb-2" style="font-size:1rem;">
                                                <span class="text-secondary" style="font-size:1.0rem;">とどめ:</span>
                                                <span class="text-orange fw-bold" title="実被弾＝バリアを抜けてHPに向かった量（HP減＋オーバーキル）">被弾 {{ number_format($v['lethal']['amount']) }}</span>
                                                <span class="text-secondary" title="軽減前の生ダメージ">基礎 {{ number_format($v['lethal']['unmit']) }}</span>
                                                @if ($v['lethal']['rate'] > 0)
                                                    <span class="text-green">軽減 -{{ $v['lethal']['rate'] }}%</span>
                                                @else
                                                    <span class="text-red fw-bold">軽減なし</span>
                                                @endif
                                                @if ($v['lethal']['absorbed'] > 0)
                                                    <span class="text-cyan" title="バリアが吸った分。被弾の値には含まれない（これが無ければ被弾はこの分だけ増えていた）">＋バリア {{ number_format($v['lethal']['absorbed']) }}</span>
                                                @endif
                                                @if ($v['lethal']['overkill'] > 0)
                                                    <span class="text-red fw-bold" title="残りHPを超えた分のダメージ（オーバーキル）。被弾の内訳。小さいほど「あと少しで耐えられた」">うち過剰 {{ number_format($v['lethal']['overkill']) }}</span>
                                                @endif
                                            </div>
                                        @endif

                                        {{-- 死亡時に乗っていた軽減・バリア --}}
                                        <div class="legacy-flex legacy-flex-col legacy-gap-1 legacy-mb-2">
                                            <div class="legacy-flex legacy-items-start legacy-gap-2" style="font-size:1.0rem;">
                                                <span class="text-secondary legacy-shrink-0" style="width:4.5rem;">軽減:</span>
                                                <div class="legacy-flex legacy-flex-wrap legacy-gap-1.5">
                                                    @forelse ($v['mit_up'] as $m)
                                                        <span class="legacy-inline-flex legacy-items-center legacy-gap-1 legacy-px-1.5 legacy-py-0.5 rounded bg-blue-lt border border-blue">
                                                            @if ($m['icon'])<img src="{{ asset('icons') }}/abilities/{{ $m['icon'] }}" class="legacy-w-5 legacy-h-5 legacy-object-contain" loading="lazy" onerror="this.style.display='none'">@endif
                                                            <span class="text-body">{{ $m['name'] }}</span>
                                                        </span>
                                                    @empty
                                                        <span class="text-red fw-bold">なし</span>
                                                    @endforelse
                                                </div>
                                            </div>
                                            <div class="legacy-flex legacy-items-start legacy-gap-2" style="font-size:1.0rem;">
                                                <span class="text-secondary legacy-shrink-0" style="width:4.5rem;">バリア:</span>
                                                <div class="legacy-flex legacy-flex-wrap legacy-gap-1.5">
                                                    @forelse ($v['barrier_up'] as $b)
                                                        <span class="legacy-inline-flex legacy-items-center legacy-gap-1 legacy-px-1.5 legacy-py-0.5 rounded bg-cyan-lt border border-cyan">
                                                            @if ($b['icon'])<img src="{{ asset('icons') }}/abilities/{{ $b['icon'] }}" class="legacy-w-5 legacy-h-5 legacy-object-contain" loading="lazy" onerror="this.style.display='none'">@endif
                                                            <span class="text-body">{{ $b['name'] }}</span>
                                                        </span>
                                                    @empty
                                                        <span class="text-secondary">なし</span>
                                                    @endforelse
                                                </div>
                                            </div>
                                        </div>

                                        {{-- 直前の被弾（〜15秒・被弾後HP付き） --}}
                                        <div class="text-secondary legacy-mb-1" style="font-size:1.0rem;">直前の被弾（〜15秒）</div>
                                        @if (count($v['sequence']) === 0)
                                            <div class="text-secondary" style="font-size:1.0rem;">被弾記録なし</div>
                                        @else
                                            <div class="legacy-space-y-0.5">
                                                @foreach ($v['sequence'] as $s)
                                                    {{-- 行間の回復（被弾後HPの上昇分を回復として推定表示） --}}
                                                    @if ($s['recovery'] > 0)
                                                        <div class="legacy-flex legacy-items-center legacy-gap-2 text-green legacy-px-1" style="font-size:1.0rem;">
                                                            <span class="font-monospace legacy-w-12 text-end legacy-shrink-0"></span>
                                                            <span class="text-center legacy-shrink-0" style="width:1.1rem;">💚</span>
                                                            <span>回復 +{{ number_format($s['recovery']) }}</span>
                                                            <span class="text-secondary font-monospace">→ HP {{ number_format($s['hp_pre']) }}</span>
                                                        </div>
                                                    @endif
                                                    <div class="legacy-flex legacy-items-center legacy-gap-2 rounded legacy-px-1 {{ $s['is_lethal'] ? 'bg-red-lt' : '' }}" style="font-size:1rem;">
                                                        <span class="font-monospace text-secondary legacy-w-12 text-end legacy-shrink-0">{{ $s['rel'] }}</span>
                                                        {{-- とどめマーカー枠（全行固定幅でアイコン列を揃える） --}}
                                                        <span class="text-red text-center legacy-shrink-0" style="width:1.1rem;font-size:1.0rem;">{{ $s['is_lethal'] ? '☠' : '' }}</span>
                                                        @if ($s['icon'])
                                                            <img src="{{ asset('icons') }}/abilities/{{ $s['icon'] }}" class="legacy-w-5 legacy-h-5 legacy-object-contain legacy-shrink-0" loading="lazy" onerror="this.style.display='none'">
                                                        @endif
                                                        <span class="text-body legacy-truncate" style="min-width:8rem;max-width:13rem;">{{ $s['name'] }}</span>
                                                        <span class="text-orange font-monospace">{{ number_format($s['amount']) }}</span>
                                                        @if ($s['rate'] > 0)
                                                            <span class="text-green" style="font-size:1.0rem;">-{{ $s['rate'] }}%</span>
                                                        @endif
                                                        @if ($s['absorbed'] > 0)
                                                            <span class="text-cyan" style="font-size:1.0rem;" title="バリアが吸った分。左の被弾値には含まれない">＋バリア{{ number_format($s['absorbed']) }}</span>
                                                        @endif
                                                        @if ($s['overkill'] > 0)
                                                            <span class="text-red fw-bold" style="font-size:1.0rem;" title="残りHPを超えた分のダメージ（オーバーキル）。左の被弾値の内訳">うち過剰{{ number_format($s['overkill']) }}</span>
                                                        @endif
                                                        @if ($s['hp_post'] !== null)
                                                            <span class="text-secondary font-monospace legacy-ml-auto" style="font-size:1.0rem;" title="被弾前HP → 被弾後HP">HP {{ number_format($s['hp_pre']) }}→{{ number_format($s['hp_post']) }}</span>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <!-- Action Filter Modal -->
    <div id="actionFilterModal" class="legacy-fixed legacy-inset-0 bg-black bg-secondary hidden legacy-flex legacy-items-center legacy-justify-center"
        style="z-index: 9999;">
        <div class="bg-surface rounded shadow-sm border border-secondary legacy-w-full legacy-max-w-2xl legacy-flex legacy-flex-col"
            style="max-height: 80vh;">
            <div class="legacy-p-4 legacy-border-b border-secondary legacy-flex legacy-justify-between legacy-items-center bg-body rounded">
                <h3 class="legacy-flex-grow fs-3 fw-bold text-white">Filter Actions</h3>
                <button onclick="closeActionFilterModal()" class="btn text-secondary ">
                    <svg class="legacy-w-6 legacy-h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M6 18L18 6M6 6l12 12">
                        </path>
                    </svg>
                </button>
            </div>

            <div id="actionFilterContent" class="legacy-p-4 overflow-y-auto legacy-flex-1 bg-surface">
                <div class="legacy-flex legacy-justify-between legacy-mb-2">
                    <button onclick="toggleAllActions(true)" class="btn text-base text-blue ">Select
                        All</button>
                    <button onclick="toggleAllActions(false)"
                        class="btn text-base text-blue ">Deselect All</button>
                </div>
                <!-- Action List Container -->
                <div id="actionList" class="legacy-grid legacy-grid-cols-2 legacy-md:grid-cols-3 legacy-gap-2">
                    <!-- Checkboxes injected by JS -->
                </div>
            </div>

            <div class="legacy-p-4 legacy-border-t border-secondary bg-body rounded legacy-flex legacy-justify-end legacy-space-x-2">
                <button onclick="closeActionFilterModal()"
                    class="btn legacy-px-4 legacy-py-2 bg-body-tertiary  text-white rounded text-base">Cancel</button>
                <button onclick="applyActionFilter()"
                    class="btn legacy-px-4 legacy-py-2 bg-blue  text-white rounded text-base fw-bold">Apply</button>
            </div>
        </div>
    </div>

    <!-- Player Timeline Modal -->
    <!-- Action Detail Modal -->
    <div id="actionDetailModal" class="legacy-fixed legacy-inset-0 bg-black bg-secondary hidden legacy-flex legacy-items-center legacy-justify-center"
        style="z-index: 10000;">
        <div class="bg-surface rounded shadow-sm border border-secondary legacy-w-full legacy-max-w-4xl legacy-flex legacy-flex-col"
            style="max-height: 80vh;">
            <div class="legacy-p-4 legacy-border-b border-secondary legacy-flex legacy-justify-between legacy-items-center bg-body rounded">
                <h3 id="actionDetailTitle" class="fs-3 fw-bold text-white">
                    <!-- Injected by JS -->
                </h3>
                <button onclick="closeActionDetailModal()" class="btn text-secondary ">
                    <svg class="legacy-w-6 legacy-h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div id="actionDetailContent" class="legacy-p-4 overflow-y-auto legacy-flex-1 bg-surface">
                <!-- Injected by JS -->
            </div>
            <div class="legacy-p-4 legacy-border-t border-secondary bg-body rounded legacy-flex legacy-justify-end">
                <button onclick="closeActionDetailModal()"
                    class="btn legacy-px-4 legacy-py-2 bg-body-tertiary  text-white rounded text-base">Close</button>
            </div>
        </div>
    </div>

    <!-- Damage Breakdown Modal（実被弾ダメージのセルをクリックで表示） -->
    <div id="damageDetailModal" class="legacy-fixed legacy-inset-0 bg-black bg-secondary hidden legacy-flex legacy-items-center legacy-justify-center"
        style="z-index: 10001;" onclick="if (event.target === this) closeDamageDetailModal()">
        <div class="bg-surface rounded shadow-sm border border-secondary legacy-w-full legacy-max-w-5xl legacy-flex legacy-flex-col"
            style="max-height: 88vh;">
            <div class="legacy-p-4 legacy-border-b border-secondary legacy-flex legacy-justify-between legacy-items-center bg-body rounded">
                <h3 id="damageDetailTitle" class="fs-3 fw-bold text-white">
                    <!-- Injected by JS -->
                </h3>
                <button onclick="closeDamageDetailModal()" class="btn text-secondary ">
                    <svg class="legacy-w-6 legacy-h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div id="damageDetailContent" class="legacy-p-4 overflow-y-auto legacy-flex-1 bg-surface legacy-space-y-4">
                <!-- Injected by JS -->
            </div>
            <div class="legacy-p-4 legacy-border-t border-secondary bg-body rounded legacy-flex legacy-justify-end">
                <button onclick="closeDamageDetailModal()"
                    class="btn legacy-px-4 legacy-py-2 bg-body-tertiary  text-white rounded text-base">Close</button>
            </div>
        </div>
    </div>

    <div id="playerTimelineModal" class="legacy-fixed legacy-inset-0 bg-black bg-secondary hidden legacy-flex legacy-items-center legacy-justify-center"
        style="z-index: 9999;">
        <div class="bg-surface rounded shadow-sm border border-secondary legacy-w-full legacy-max-w-3xl legacy-flex legacy-flex-col"
            style="max-height: 80vh;">
            <div class="legacy-p-4 legacy-border-b border-secondary legacy-flex legacy-justify-between legacy-items-center bg-body rounded">
                <h3 id="playerModalTitle" class="fs-3 fw-bold text-white legacy-flex-grow legacy-flex legacy-items-center">
                    <!-- Injected by JS -->
                </h3>
                <button onclick="saveModalAsImage('playerTimelineContent', 'player_timeline.png')"
                    class="btn legacy-mr-4 legacy-px-3 legacy-py-1 bg-green  text-white rounded text-base fw-bold shadow ">Save
                    Image</button>

                <button onclick="closePlayerTimelineModal()" class="btn text-secondary ">
                    <svg class="legacy-w-6 legacy-h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div id="playerTimelineContent" class="legacy-p-4 overflow-y-auto legacy-flex-1 bg-surface legacy-space-y-2">
                <!-- Injected by JS -->
            </div>

            <div class="legacy-p-4 legacy-border-t border-secondary bg-body rounded legacy-flex legacy-justify-end">
                <button onclick="closePlayerTimelineModal()"
                    class="btn legacy-px-4 legacy-py-2 bg-body-tertiary  text-white rounded text-base">Close</button>
            </div>
        </div>
    </div>
    <div class="timeline-x-controls" id="timeline-x-controls" hidden><button type="button" id="timeline-scroll-left" aria-label="表を左へスクロール">←</button><input type="range" class="form-range m-0" id="timeline-xrange" min="0" max="0" value="0" step="1" aria-label="表の横スクロール位置"><button type="button" id="timeline-scroll-right" aria-label="表を右へスクロール">→</button></div>

    @include('_copyright', ['compact' => true])
@if (($shareDraft ?? false) || ($isShared ?? false))
    @include('fflogs._timeline_share')
    @endif
</body>

</html>
