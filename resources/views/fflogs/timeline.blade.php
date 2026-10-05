<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timeline - {{ $fight['name'] }}</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="{{ asset('css/timeline.css') }}?v={{ @filemtime(public_path('css/timeline.css')) }}" rel="stylesheet">
</head>

<body class="bg-gray-900 text-gray-200 h-screen w-screen overflow-hidden flex flex-col m-0">
    <div class="w-full flex-none bg-gray-900 border-b border-gray-800 z-50 p-4">
        <header class="flex justify-between items-center">
            <div>
                <h2 class="text-xl font-bold flex items-center">
                    <span class="text-gray-400 mr-2">Timeline:</span> {{ $fight['name'] }}
                </h2>
                <div class="flex items-center space-x-4 mt-2">
                    <!-- Player Columns Toggle -->
                    <button onclick="downloadHojoringXML()"
                        class="mr-2 px-3 py-1 bg-green-600 hover:bg-green-500 rounded text-sm text-white font-bold">
                        Export XML
                    </button>
                    <button onclick="togglePlayers()"
                        class="px-3 py-1 bg-gray-700 hover:bg-gray-600 rounded text-xs text-white border border-gray-600 transition-colors">
                        Toggle Players
                    </button>

                    <!-- Custom Action Filter -->
                    <button onclick="openActionFilterModal()"
                        class="px-3 py-1 bg-gray-700 hover:bg-gray-600 rounded text-xs text-white border border-gray-600 transition-colors">
                        Filter Actions
                    </button>
                    <!-- Full Timeline Export -->
                    <button onclick="saveFullTimeline()"
                        class="px-3 py-1 bg-green-600 hover:bg-green-500 rounded text-xs text-white border border-green-500 transition-colors shadow">
                        Save Full Timeline
                    </button>

                    <script>
                        // Pass server-side data to global scope for external JS
                        window.playerTimelines = @json($playerTimelines);
                        window.playerDetails = @json($playerDetails);
                        window.enemyEvents = @json($events);
                        window.mitCols = @json($mitigationColumns);
                        window.fightMetadata = @json($fight);
                        window.ICON_BASE = @json(asset('icons'));
                    </script>
                    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
                    <script src="{{ asset('js/timeline.js') }}?v={{ @filemtime(public_path('js/timeline.js')) }}"></script>
                    <p class="text-gray-400 text-sm ml-4 border-l border-gray-600 pl-4 h-full flex items-center">
                        Duration:
                        {{ gmdate('i:s', ($fight['endTime'] - $fight['startTime']) / 1000) }}</p>
                </div>
            </div>
            <a href="{{ route('mitigation.form') }}" class="text-indigo-400 hover:text-indigo-300">New Analysis</a>
        </header>

        <!-- ビュー切り替えタブ -->
        <div class="flex items-center gap-2 mt-3">
            <button id="tab-btn-mitigation" onclick="switchView('mitigation')"
                class="view-tab px-4 py-1.5 rounded-t-md text-sm font-bold border border-b-0 border-gray-600 bg-gray-700 text-white">
                🛡️ 軽減・バリア
            </button>
            <button id="tab-btn-synergy" onclick="switchView('synergy')"
                class="view-tab px-4 py-1.5 rounded-t-md text-sm font-bold border border-b-0 border-gray-700 bg-gray-900 text-gray-400 hover:text-white">
                ⚔️ シナジー・バフ
            </button>
            @php $totalDeaths = array_sum(array_map(fn($g) => $g['victim_count'], $deaths)); @endphp
            <button id="tab-btn-death" onclick="switchView('death')"
                class="view-tab px-4 py-1.5 rounded-t-md text-sm font-bold border border-b-0 border-gray-700 bg-gray-900 text-gray-400 hover:text-white">
                💀 デスチェッカー @if ($totalDeaths)<span class="ml-1 text-red-400">({{ $totalDeaths }})</span>@endif
            </button>
        </div>
    </div>

    <!-- ===== 軽減・バリア ビュー ===== -->
    <div id="view-mitigation" class="flex-1 w-full overflow-auto bg-gray-900 relative">
        <table class="w-full text-left border-separate min-w-max {{ $hasPhases ? 'has-phase' : '' }}">
            <thead class="bg-gray-800 text-gray-400">
                <!-- Row 1: Groups & Main Headers -->
                <tr class="h-10">
                    @if ($hasPhases)
                        <th rowspan="2" style="position: sticky; top: 0; left: 0; z-index: 66;"
                            class="p-1 w-12 bg-gray-800 align-middle shadow-sm text-center text-xs">
                            Phase</th>
                    @endif
                    <th rowspan="2" style="position: sticky; top: 0; left: {{ $hasPhases ? '3rem' : '0' }}; z-index: 65;"
                        class="p-1 w-16 bg-gray-800 align-middle shadow-sm">
                        Time</th>
                    <th rowspan="2" style="position: sticky; top: 0; left: {{ $hasPhases ? '7rem' : '4rem' }}; z-index: 65;"
                        class="p-1 bg-gray-800 align-middle w-48 shadow-sm">
                        Action</th>
                    <th rowspan="2" style="position: sticky; top: 0; left: {{ $hasPhases ? '15rem' : '12rem' }}; z-index: 65;"
                        class="p-1 text-right bg-gray-800 align-middle w-16 shadow-sm text-xs"
                        title="素のダメージ＝軽減・バリアが一切無かった場合のダメージ（グループ内の最大値）。バリア吸収分もここに含まれるため、Base Dmg − Hit Dmg は軽減量とは一致しない">
                        Base Dmg</th>
                    <th rowspan="2" style="position: sticky; top: 0; left: {{ $hasPhases ? '19rem' : '16rem' }}; z-index: 65;"
                        class="p-1 text-right bg-gray-800 align-middle w-16 text-xs sticky-divider shadow-sm"
                        title="実被弾＝バリアを抜けてHPに向かった量（オーバーキル込み・グループ内の最大値）。(-xx%) は軽減率（素のダメージ→軽減後ダメージ。バリア吸収は含まない）。クリックで内訳">
                        Hit Dmg</th>

                    <!-- Dynamic Player Headers (Rowspanned) -->
                    @foreach ($playerColumns as $player)
                        @php $pDetail = $playerDetails[$player] ?? ['icon' => null, 'name' => $player]; @endphp
                        <th rowspan="2" data-player="{{ $player }}"
                            class="p-1 border border-gray-600 bg-gray-700 text-white sticky w-[50px] min-w-[50px] player-col"
                            style="top: 0; z-index: 60; background-color: rgb(31, 41, 55);">
                            <div class="flex flex-col items-center justify-center gap-1">
                                @if ($pDetail['icon'])
                                    <img src="{{ $pDetail['icon'] }}" loading="lazy"
                                        class="w-6 h-6 object-contain rounded-sm" onerror="this.style.display='none'">
                                @endif
                                <div class="truncate w-full p-1 text-center text-xs text-gray-400">
                                    {{ substr($pDetail['name'], 0, 5) }}</div>
                            </div>
                        </th>
                    @endforeach

                    <!-- Mitigation Groups -->
                    @foreach ($mitigationGroups as $groupName => $cols)
                        <th class="p-1 border border-gray-600 bg-gray-800 text-white font-bold sticky top-0 z-[60] cursor-pointer hover:bg-gray-700 group"
                            style="top: 0; z-index: 60; background-color: rgb(31, 41, 55);"
                            colspan="{{ count($cols) }}" onclick="openJobTimeline('{{ $groupName }}')">
                            <div class="flex items-center justify-center gap-1">
                                @if (isset($mitigationGroupIcons[$groupName]))
                                    <img src="{{ $mitigationGroupIcons[$groupName] }}" class="w-5 h-5" loading="lazy">
                                @endif
                                <span
                                    class="text-xs group-hover:text-blue-400 group-hover:underline">{{ $groupName }}</span>
                            </div>
                        </th>
                    @endforeach
                </tr>
                <!-- Row 2: Mitigation Icons -->
                <tr class="h-10">
                    @foreach ($mitigationGroups as $groupName => $cols)
                        @foreach ($cols as $key => $col)
                            <th style="position: sticky; top: 2.5rem; z-index: 40;"
                                class="py-1 px-0 w-8 text-center bg-gray-800 h-10 align-middle group relative">
                                @php $skillName = $col['name'] ?? $key; @endphp
                                <div class="flex flex-col items-center justify-center">
                                    @if ($col['icon'])
                                        <img src="{{ asset('icons') }}/abilities/{{ $col['icon'] }}"
                                            class="w-6 h-6 object-contain mb-1" alt="{{ $skillName }}" loading="lazy"
                                            onerror="this.style.display='none'; this.nextElementSibling.style.display='block'"
                                            title="{{ $skillName }}">
                                        <span class="text-[10px] hidden">{{ $skillName }}</span>
                                    @else
                                        <span class="text-[10px]">{{ $skillName }}</span>
                                    @endif
                                </div>
                            </th>
                        @endforeach
                    @endforeach
                </tr>
            </thead>
            <tbody class="text-sm divide-y divide-gray-700">
                @php $prevPhase = null; @endphp
                @foreach ($events as $event)
                    @php $curPhase = $event['phase'] ?? null; @endphp
                    <tr class="hover:bg-gray-800 transition-colors {{ $event['is_dot'] ? 'row-dot' : '' }}">

                        @if ($hasPhases)
                            <td class="c-phase phase-p{{ $curPhase % 6 }} p-1 text-center font-bold bg-gray-900"
                                title="Phase {{ $curPhase }}">
                                @if ($curPhase !== $prevPhase)P{{ $curPhase }}@endif
                            </td>
                            @php $prevPhase = $curPhase; @endphp
                        @endif
                        <td class="c-time p-1 font-mono text-gray-400 bg-gray-900 w-16">
                            {{ gmdate('i:s', $event['rel_time']) }}
                        </td>
                        <td class="c-action p-1 font-bold text-white bg-gray-900 ability-name truncate w-48 max-w-[12rem] cursor-pointer hover:text-blue-400 hover:underline transition-colors"
                            onclick="openActionDetail('{{ $event['ability'] }}', {{ $event['timestamp'] }})">
                            {{ $event['ability'] }}
                        </td>
                        <td class="c-base p-1 text-right text-gray-400 font-mono bg-gray-900 text-xs w-16">
                            <div>{{ number_format($event['max_unmitigated'] ?? 0) }}</div>
                        </td>
                        @php $hasHit = ($event['max_amount'] ?? 0) > 0; @endphp
                        <td class="c-hit p-1 text-right text-orange-300 font-mono font-bold bg-gray-900 px-2 text-xs w-16 sticky-divider shadow-sm {{ $hasHit ? 'cursor-pointer hover:bg-gray-800 hover:text-orange-200 hover:underline' : '' }}"
                            @if ($hasHit) onclick="openDamageDetail('{{ addslashes($event['ability']) }}', {{ $event['timestamp'] }})" title="クリックで被弾内訳（軽減スキル・バリア・実被弾）を表示" @endif>
                            <div>{{ number_format($event['max_amount'] ?? 0) }}</div>
                            @php
                                $hitRate = $event['mitigation_rate'] ?? 0;
                                $barrierAll = $event['absorbed_total'] ?? 0;
                                $barrierMax = $event['max_absorbed'] ?? 0;
                                // インライン要素どうしの間にBladeのインデントが空白テキストとして
                                // 入ってしまうため、断片を組み立ててから隙間なしで出力する
                                $hitBadges = [];
                                if ($hitRate > 0) {
                                    $hitBadges[] = '<span class="text-green-400">(-' . (int) $hitRate . '%)</span>';
                                }
                                if ($barrierAll > 0) {
                                    $barrierTip = 'バリアが吸収 ' . number_format($barrierMax)
                                        . '（最大被弾者）／グループ合計 ' . number_format($barrierAll)
                                        . '。実被弾には含まれない';
                                    $hitBadges[] = '<span class="text-cyan-300 font-bold text-[9px]" title="'
                                        . e($barrierTip) . '">+B</span>';
                                }
                            @endphp
                            @if ($hitBadges)
                                <span class="block text-[10px] font-normal leading-tight whitespace-nowrap">{!! implode('', $hitBadges) !!}</span>
                            @endif
                        </td>

                        <!-- Player Cells -->
                        @foreach ($playerColumns as $player)
                            <td class="p-1 text-center text-xs player-col hidden md:table-cell"
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
                                    <div class="flex flex-col items-center" title="{{ $pTip }}">
                                        <span class="text-red-400">
                                            {{ number_format($pAmt) }}
                                        </span>
                                        @if ($pMitRate > 0)
                                            <span class="text-green-500 text-[9px]">-{{ $pMitRate }}%</span>
                                        @endif
                                        @if ($pOverkill > 0)
                                            <span class="text-red-500 text-[9px]" title="この一撃で死亡">☠</span>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-gray-700">-</span>
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
            <div class="p-8 text-center text-gray-500">
                No events found.
            </div>
        @endif
    </div>

    <!-- ===== シナジー・バフ ビュー（既定は非表示） ===== -->
    <div id="view-synergy" class="flex-1 w-full overflow-auto bg-gray-900 relative hidden">
        @if (count($synergyWindows) === 0)
            <div class="p-8 text-center text-gray-500" style="font-size:1.05rem;">シナジーバフの使用が見つかりませんでした。</div>
        @else
            <div class="p-3 space-y-4">
                @foreach ($synergyWindows as $win)
                    <div class="syn-window rounded-lg border border-gray-700 bg-gray-800/40 overflow-hidden">
                        <div class="syn-window-head flex items-center gap-3 px-4 py-2">
                            <span class="text-amber-300 font-bold" style="font-size:1.15rem;">🔥 バースト窓 #{{ $win['index'] + 1 }}</span>
                            <span class="text-gray-300 font-mono" style="font-size:1.05rem;">{{ gmdate('i:s', (int) $win['start']) }}〜{{ gmdate('i:s', (int) $win['end']) }}</span>
                            <span class="ml-auto text-gray-400" style="font-size:1rem;">{{ $win['players_count'] }}人 / {{ $win['total'] }}件</span>
                        </div>
                        <div class="divide-y divide-gray-700/60">
                            {{-- 人単位で1行：その人が窓内で使ったシナジースキルを横並び --}}
                            @foreach ($win['players'] as $p)
                                <div class="syn-row flex items-start gap-3 px-4 py-2 hover:bg-gray-700/40">
                                    <span class="font-mono text-gray-500 w-12 text-right shrink-0" style="font-size:1rem; padding-top:0.2rem;">{{ gmdate('i:s', (int) $p['first_time']) }}</span>
                                    {{-- 使用者（名前は折り返さず1行） --}}
                                    <span class="flex items-center gap-2 shrink-0 whitespace-nowrap" style="min-width:13rem;">
                                        @if ($p['player_icon'])
                                            <img src="{{ $p['player_icon'] }}" class="w-7 h-7 object-contain rounded-sm shrink-0" loading="lazy"
                                                onerror="this.style.display='none'">
                                        @endif
                                        <span class="text-white font-bold whitespace-nowrap" style="font-size:1.05rem;">{{ $p['player'] }}</span>
                                        <span class="text-gray-500 shrink-0" style="font-size:0.95rem;">{{ $p['job'] }}</span>
                                    </span>
                                    {{-- 使ったスキルをチップで横並び --}}
                                    <div class="flex flex-wrap items-center gap-2">
                                        @foreach ($p['skills'] as $sk)
                                            @php
                                                if ($sk['is_item']) { $chipClass = 'chip-item'; }
                                                elseif ($sk['cd'] == 120) { $chipClass = 'chip-cd120'; }
                                                elseif ($sk['cd'] == 60) { $chipClass = 'chip-cd60'; }
                                                else { $chipClass = 'chip-other'; }
                                            @endphp
                                            <span class="syn-chip {{ $chipClass }}">
                                                @if ($sk['skill_icon'])
                                                    <img src="{{ asset('icons') }}/abilities/{{ $sk['skill_icon'] }}" class="w-5 h-5 object-contain" loading="lazy"
                                                        alt="{{ $sk['skill'] }}" onerror="this.style.display='none'">
                                                @elseif ($sk['is_item'])
                                                    <span>💊</span>
                                                @endif
                                                <span>{{ $sk['skill'] }}</span>
                                                @unless ($sk['self_target'])
                                                    <span class="opacity-70" style="font-size:0.85rem;">▸{{ $sk['target'] }}</span>
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
    <div id="view-death" class="flex-1 w-full overflow-auto bg-gray-900 relative hidden">
        @if (count($deaths) === 0)
            <div class="p-8 text-center text-gray-500" style="font-size:1.05rem;">この戦闘では死亡者がいませんでした。🎉</div>
        @else
            <div class="p-3 space-y-4">
                @foreach ($deaths as $g)
                    <div class="death-card rounded-lg border border-gray-700 bg-gray-800/40 overflow-hidden">
                        {{-- ヘッダー：時刻帯・とどめスキル・死亡者まとめ --}}
                        <div class="death-head flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 bg-gray-800/70">
                            <span class="text-red-400 font-bold" style="font-size:1.15rem;">💀 #{{ $g['index'] + 1 }}</span>
                            <span class="text-gray-300 font-mono" style="font-size:1.05rem;">
                                {{ $g['time_start'] }}@if ($g['multi_time'])〜{{ $g['time_end'] }}@endif
                            </span>
                            <span class="flex items-center gap-2 text-amber-300" style="font-size:1.05rem;">
                                <span class="text-gray-400" style="font-size:1.0rem;">死因:</span>
                                @if ($g['kill_ability']['icon'])
                                    <img src="{{ asset('icons') }}/abilities/{{ $g['kill_ability']['icon'] }}" class="w-6 h-6 object-contain" loading="lazy"
                                        onerror="this.style.display='none'">
                                @endif
                                <span class="font-bold">{{ $g['kill_ability']['name'] }}</span>
                            </span>
                            @if (count($g['raidwide_names']))
                                <span class="inline-flex items-center px-2 py-0.5 rounded bg-purple-900/50 border border-purple-600/60 text-purple-200 font-bold" style="font-size:1rem;" title="アリーナ全体に及ぶ攻撃（回避不可）。{{ implode('・', $g['raidwide_names']) }}">
                                    🌐 全体攻撃
                                </span>
                            @endif
                            <span class="ml-auto inline-flex items-center px-2 py-0.5 rounded-full bg-red-900/50 border border-red-700/60 text-red-200 font-bold" style="font-size:1rem;">
                                {{ $g['victim_count'] }}人 死亡
                            </span>
                            {{-- 死亡者チップ（まとめ） --}}
                            <div class="w-full flex flex-wrap items-center gap-1.5 mt-1">
                                @foreach ($g['victims'] as $v)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-gray-900/60 border border-gray-600" style="font-size:1.0rem;">
                                        <span style="font-size:1.0rem;">💀</span>
                                        @if ($v['victim']['icon'])<img src="{{ $v['victim']['icon'] }}" class="w-4 h-4" loading="lazy" onerror="this.style.display='none'">@endif
                                        <span class="text-white font-bold">{{ $v['victim']['name'] }}</span>
                                        <span class="text-gray-500" style="font-size:1.0rem;">{{ $v['victim']['job'] }}</span>
                                        <span class="text-gray-400 font-mono" style="font-size:1.0rem;">{{ $v['rel_time'] }}</span>
                                    </span>
                                @endforeach
                            </div>
                        </div>

                        <div class="flex flex-col lg:flex-row gap-4 p-4">
                            {{-- 左：円形マップ --}}
                            <div class="shrink-0 flex flex-col items-center gap-2">
                                <svg viewBox="0 0 200 200" width="320" height="320" class="death-map rounded-full border border-gray-600 bg-gray-900">
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
                                                <text x="{{ $dot['sx'] }}" y="{{ $dot['sy'] }}" text-anchor="middle" dominant-baseline="central" style="font-size:7px;" class="select-none">❌</text>
                                                <text x="{{ $dot['sx'] }}" y="{{ $dot['sy'] - 6 }}" text-anchor="middle" fill="#9ca3af" style="font-size:6px;" class="select-none">{{ mb_substr($dot['name'], 0, 4) }}</text>
                                            </g>
                                        @else
                                            @if ($dot['is_dead'])
                                                <circle cx="{{ $dot['sx'] }}" cy="{{ $dot['sy'] }}" r="8" fill="none" stroke="#ef4444" stroke-width="1" opacity="0.8" />
                                            @elseif ($dot['is_hit'])
                                                <circle cx="{{ $dot['sx'] }}" cy="{{ $dot['sy'] }}" r="7" fill="none" stroke="#f97316" stroke-width="1" stroke-dasharray="2 1.5" opacity="0.8" />
                                            @endif
                                            <circle cx="{{ $dot['sx'] }}" cy="{{ $dot['sy'] }}" r="4.2" fill="{{ $dotColor }}" stroke="#0b0f1a" stroke-width="0.8" />
                                            @if ($dot['is_dead'])
                                                <text x="{{ $dot['sx'] }}" y="{{ $dot['sy'] }}" text-anchor="middle" dominant-baseline="central" style="font-size:7px;" class="select-none">💀</text>
                                            @endif
                                            <text x="{{ $dot['sx'] }}" y="{{ $dot['sy'] - 6 }}" text-anchor="middle" fill="{{ $dot['is_dead'] ? '#fca5a5' : '#e5e7eb' }}" style="font-size:6px;" class="select-none">{{ mb_substr($dot['name'], 0, 4) }}</text>
                                        @endif
                                    @endforeach
                                </svg>
                                <div class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-gray-400" style="font-size:1.0rem;">
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
                            <div class="flex-1 min-w-0 space-y-3">
                                {{-- 被弾比較（このグループのとどめスキル） --}}
                                <div class="rounded border border-gray-700 bg-gray-900/50 p-3">
                                    <div class="text-gray-400 mb-2" style="font-size:1.0rem;">「{{ $g['kill_ability']['name'] }}」の被弾比較</div>
                                    <div class="flex flex-col sm:flex-row gap-3">
                                        <div class="flex-1">
                                            <div class="text-red-400 mb-1" style="font-size:1.0rem;">被弾した人 ({{ count($g['hit_players']) }})</div>
                                            <div class="flex flex-wrap gap-1.5">
                                                @forelse ($g['hit_players'] as $p)
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-red-900/40 border border-red-700/50" style="font-size:1.0rem;">
                                                        @if ($p['icon'])<img src="{{ $p['icon'] }}" class="w-4 h-4" loading="lazy" onerror="this.style.display='none'">@endif
                                                        <span class="text-gray-200">{{ $p['name'] }}</span>
                                                    </span>
                                                @empty
                                                    <span class="text-gray-600" style="font-size:1.0rem;">—</span>
                                                @endforelse
                                            </div>
                                        </div>
                                        <div class="flex-1">
                                            <div class="text-green-400 mb-1" style="font-size:1.0rem;">回避した人 ({{ count($g['safe_players']) }})</div>
                                            <div class="flex flex-wrap gap-1.5">
                                                @forelse ($g['safe_players'] as $p)
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-green-900/30 border border-green-700/40" style="font-size:1.0rem;">
                                                        @if ($p['icon'])<img src="{{ $p['icon'] }}" class="w-4 h-4" loading="lazy" onerror="this.style.display='none'">@endif
                                                        <span class="text-gray-300">{{ $p['name'] }}</span>
                                                    </span>
                                                @empty
                                                    <span class="text-gray-600" style="font-size:1.0rem;">—</span>
                                                @endforelse
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- 死亡者ごとの詳細（とどめ・致死前HP・直前被弾） --}}
                                @foreach ($g['victims'] as $v)
                                    <div class="rounded border border-gray-700 bg-gray-900/50 p-3">
                                        <div class="flex items-center gap-2 mb-2">
                                            <span style="font-size:1.0rem;">💀</span>
                                            @if ($v['victim']['icon'])<img src="{{ $v['victim']['icon'] }}" class="w-5 h-5" loading="lazy" onerror="this.style.display='none'">@endif
                                            <span class="text-white font-bold" style="font-size:1.05rem;">{{ $v['victim']['name'] }}</span>
                                            <span class="text-gray-500" style="font-size:1.0rem;">{{ $v['victim']['job'] }}</span>
                                            <span class="text-gray-400 font-mono ml-auto" style="font-size:1.0rem;">{{ $v['rel_time'] }}</span>
                                        </div>

                                        {{-- 致死前HP バー --}}
                                        @if ($v['hp_before'] !== null && $v['maxhp'])
                                            @php $hpPct = max(0, min(100, round($v['hp_before'] / max(1, $v['maxhp']) * 100))); @endphp
                                            <div class="mb-2">
                                                <div class="flex justify-between text-gray-400 mb-0.5" style="font-size:1.0rem;">
                                                    <span>致死前HP</span>
                                                    <span class="font-mono">{{ number_format($v['hp_before']) }} / {{ number_format($v['maxhp']) }}（{{ $hpPct }}%）</span>
                                                </div>
                                                <div class="w-full h-2.5 rounded bg-gray-700 overflow-hidden">
                                                    <div class="h-full rounded {{ $hpPct <= 30 ? 'bg-red-500' : ($hpPct <= 60 ? 'bg-yellow-500' : 'bg-green-500') }}" style="width: {{ $hpPct }}%;"></div>
                                                </div>
                                            </div>
                                        @endif

                                        {{-- とどめの一撃 --}}
                                        @if ($v['lethal'])
                                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mb-2" style="font-size:1rem;">
                                                <span class="text-gray-400" style="font-size:1.0rem;">とどめ:</span>
                                                <span class="text-orange-300 font-bold" title="実被弾＝バリアを抜けてHPに向かった量（HP減＋オーバーキル）">被弾 {{ number_format($v['lethal']['amount']) }}</span>
                                                <span class="text-gray-400" title="軽減前の生ダメージ">基礎 {{ number_format($v['lethal']['unmit']) }}</span>
                                                @if ($v['lethal']['rate'] > 0)
                                                    <span class="text-green-400">軽減 -{{ $v['lethal']['rate'] }}%</span>
                                                @else
                                                    <span class="text-red-400 font-bold">軽減なし</span>
                                                @endif
                                                @if ($v['lethal']['absorbed'] > 0)
                                                    <span class="text-cyan-300" title="バリアが吸った分。被弾の値には含まれない（これが無ければ被弾はこの分だけ増えていた）">＋バリア {{ number_format($v['lethal']['absorbed']) }}</span>
                                                @endif
                                                @if ($v['lethal']['overkill'] > 0)
                                                    <span class="text-red-500 font-bold" title="残りHPを超えた分のダメージ（オーバーキル）。被弾の内訳。小さいほど「あと少しで耐えられた」">うち過剰 {{ number_format($v['lethal']['overkill']) }}</span>
                                                @endif
                                            </div>
                                        @endif

                                        {{-- 死亡時に乗っていた軽減・バリア --}}
                                        <div class="flex flex-col gap-1 mb-2">
                                            <div class="flex items-start gap-2" style="font-size:1.0rem;">
                                                <span class="text-gray-400 shrink-0" style="width:4.5rem;">軽減:</span>
                                                <div class="flex flex-wrap gap-1.5">
                                                    @forelse ($v['mit_up'] as $m)
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-blue-900/30 border border-blue-700/40">
                                                            @if ($m['icon'])<img src="{{ asset('icons') }}/abilities/{{ $m['icon'] }}" class="w-5 h-5 object-contain" loading="lazy" onerror="this.style.display='none'">@endif
                                                            <span class="text-gray-200">{{ $m['name'] }}</span>
                                                        </span>
                                                    @empty
                                                        <span class="text-red-400 font-bold">なし</span>
                                                    @endforelse
                                                </div>
                                            </div>
                                            <div class="flex items-start gap-2" style="font-size:1.0rem;">
                                                <span class="text-gray-400 shrink-0" style="width:4.5rem;">バリア:</span>
                                                <div class="flex flex-wrap gap-1.5">
                                                    @forelse ($v['barrier_up'] as $b)
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-cyan-900/30 border border-cyan-700/40">
                                                            @if ($b['icon'])<img src="{{ asset('icons') }}/abilities/{{ $b['icon'] }}" class="w-5 h-5 object-contain" loading="lazy" onerror="this.style.display='none'">@endif
                                                            <span class="text-gray-200">{{ $b['name'] }}</span>
                                                        </span>
                                                    @empty
                                                        <span class="text-gray-500">なし</span>
                                                    @endforelse
                                                </div>
                                            </div>
                                        </div>

                                        {{-- 直前の被弾（〜15秒・被弾後HP付き） --}}
                                        <div class="text-gray-400 mb-1" style="font-size:1.0rem;">直前の被弾（〜15秒）</div>
                                        @if (count($v['sequence']) === 0)
                                            <div class="text-gray-600" style="font-size:1.0rem;">被弾記録なし</div>
                                        @else
                                            <div class="space-y-0.5">
                                                @foreach ($v['sequence'] as $s)
                                                    {{-- 行間の回復（被弾後HPの上昇分を回復として推定表示） --}}
                                                    @if ($s['recovery'] > 0)
                                                        <div class="flex items-center gap-2 text-green-400 px-1" style="font-size:1.0rem;">
                                                            <span class="font-mono w-12 text-right shrink-0"></span>
                                                            <span class="text-center shrink-0" style="width:1.1rem;">💚</span>
                                                            <span>回復 +{{ number_format($s['recovery']) }}</span>
                                                            <span class="text-gray-500 font-mono">→ HP {{ number_format($s['hp_pre']) }}</span>
                                                        </div>
                                                    @endif
                                                    <div class="flex items-center gap-2 rounded px-1 {{ $s['is_lethal'] ? 'bg-red-900/30' : '' }}" style="font-size:1rem;">
                                                        <span class="font-mono text-gray-500 w-12 text-right shrink-0">{{ $s['rel'] }}</span>
                                                        {{-- とどめマーカー枠（全行固定幅でアイコン列を揃える） --}}
                                                        <span class="text-red-400 text-center shrink-0" style="width:1.1rem;font-size:1.0rem;">{{ $s['is_lethal'] ? '☠' : '' }}</span>
                                                        @if ($s['icon'])
                                                            <img src="{{ asset('icons') }}/abilities/{{ $s['icon'] }}" class="w-5 h-5 object-contain shrink-0" loading="lazy" onerror="this.style.display='none'">
                                                        @endif
                                                        <span class="text-gray-200 truncate" style="min-width:8rem;max-width:13rem;">{{ $s['name'] }}</span>
                                                        <span class="text-orange-300 font-mono">{{ number_format($s['amount']) }}</span>
                                                        @if ($s['rate'] > 0)
                                                            <span class="text-green-500" style="font-size:1.0rem;">-{{ $s['rate'] }}%</span>
                                                        @endif
                                                        @if ($s['absorbed'] > 0)
                                                            <span class="text-cyan-300" style="font-size:1.0rem;" title="バリアが吸った分。左の被弾値には含まれない">＋バリア{{ number_format($s['absorbed']) }}</span>
                                                        @endif
                                                        @if ($s['overkill'] > 0)
                                                            <span class="text-red-500 font-bold" style="font-size:1.0rem;" title="残りHPを超えた分のダメージ（オーバーキル）。左の被弾値の内訳">うち過剰{{ number_format($s['overkill']) }}</span>
                                                        @endif
                                                        @if ($s['hp_post'] !== null)
                                                            <span class="text-gray-400 font-mono ml-auto" style="font-size:1.0rem;" title="被弾前HP → 被弾後HP">HP {{ number_format($s['hp_pre']) }}→{{ number_format($s['hp_post']) }}</span>
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
    <div id="actionFilterModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center"
        style="z-index: 9999;">
        <div class="bg-gray-800 rounded-lg shadow-xl border border-gray-700 w-full max-w-2xl flex flex-col"
            style="max-height: 80vh;">
            <div class="p-4 border-b border-gray-700 flex justify-between items-center bg-gray-900 rounded-t-lg">
                <h3 class="flex-grow text-lg font-bold text-white">Filter Actions</h3>
                <button onclick="closeActionFilterModal()" class="text-gray-400 hover:text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M6 18L18 6M6 6l12 12">
                        </path>
                    </svg>
                </button>
            </div>

            <div id="actionFilterContent" class="p-4 overflow-y-auto flex-1 bg-gray-800">
                <div class="flex justify-between mb-2">
                    <button onclick="toggleAllActions(true)" class="text-xs text-blue-400 hover:text-blue-300">Select
                        All</button>
                    <button onclick="toggleAllActions(false)"
                        class="text-xs text-blue-400 hover:text-blue-300">Deselect All</button>
                </div>
                <!-- Action List Container -->
                <div id="actionList" class="grid grid-cols-2 md:grid-cols-3 gap-2">
                    <!-- Checkboxes injected by JS -->
                </div>
            </div>

            <div class="p-4 border-t border-gray-700 bg-gray-900 rounded-b-lg flex justify-end space-x-2">
                <button onclick="closeActionFilterModal()"
                    class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded text-sm">Cancel</button>
                <button onclick="applyActionFilter()"
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white rounded text-sm font-bold">Apply</button>
            </div>
        </div>
    </div>

    <!-- Player Timeline Modal -->
    <!-- Action Detail Modal -->
    <div id="actionDetailModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center"
        style="z-index: 10000;">
        <div class="bg-gray-800 rounded-lg shadow-xl border border-gray-700 w-full max-w-4xl flex flex-col"
            style="max-height: 80vh;">
            <div class="p-4 border-b border-gray-700 flex justify-between items-center bg-gray-900 rounded-t-lg">
                <h3 id="actionDetailTitle" class="text-lg font-bold text-white">
                    <!-- Injected by JS -->
                </h3>
                <button onclick="closeActionDetailModal()" class="text-gray-400 hover:text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div id="actionDetailContent" class="p-4 overflow-y-auto flex-1 bg-gray-800">
                <!-- Injected by JS -->
            </div>
            <div class="p-4 border-t border-gray-700 bg-gray-900 rounded-b-lg flex justify-end">
                <button onclick="closeActionDetailModal()"
                    class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded text-sm">Close</button>
            </div>
        </div>
    </div>

    <!-- Damage Breakdown Modal（実被弾ダメージのセルをクリックで表示） -->
    <div id="damageDetailModal" class="fixed inset-0 bg-black bg-opacity-60 hidden flex items-center justify-center"
        style="z-index: 10001;" onclick="if (event.target === this) closeDamageDetailModal()">
        <div class="bg-gray-800 rounded-lg shadow-xl border border-gray-700 w-full max-w-5xl flex flex-col"
            style="max-height: 88vh;">
            <div class="p-4 border-b border-gray-700 flex justify-between items-center bg-gray-900 rounded-t-lg">
                <h3 id="damageDetailTitle" class="text-lg font-bold text-white">
                    <!-- Injected by JS -->
                </h3>
                <button onclick="closeDamageDetailModal()" class="text-gray-400 hover:text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div id="damageDetailContent" class="p-4 overflow-y-auto flex-1 bg-gray-800 space-y-4">
                <!-- Injected by JS -->
            </div>
            <div class="p-4 border-t border-gray-700 bg-gray-900 rounded-b-lg flex justify-end">
                <button onclick="closeDamageDetailModal()"
                    class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded text-sm">Close</button>
            </div>
        </div>
    </div>

    <div id="playerTimelineModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center"
        style="z-index: 9999;">
        <div class="bg-gray-800 rounded-lg shadow-xl border border-gray-700 w-full max-w-3xl flex flex-col"
            style="max-height: 80vh;">
            <div class="p-4 border-b border-gray-700 flex justify-between items-center bg-gray-900 rounded-t-lg">
                <h3 id="playerModalTitle" class="text-lg font-bold text-white flex-grow flex items-center">
                    <!-- Injected by JS -->
                </h3>
                <button onclick="saveModalAsImage('playerTimelineContent', 'player_timeline.png')"
                    class="mr-4 px-3 py-1 bg-green-600 hover:bg-green-700 text-white rounded text-sm font-bold shadow transition">Save
                    Image</button>

                <button onclick="closePlayerTimelineModal()" class="text-gray-400 hover:text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div id="playerTimelineContent" class="p-4 overflow-y-auto flex-1 bg-gray-800 space-y-2">
                <!-- Injected by JS -->
            </div>

            <div class="p-4 border-t border-gray-700 bg-gray-900 rounded-b-lg flex justify-end">
                <button onclick="closePlayerTimelineModal()"
                    class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded text-sm">Close</button>
            </div>
        </div>
    </div>
    @include('_copyright', ['compact' => true])
</body>

</html>
