{{--
    フェーズカードと「グラフで選択した範囲」で共通の3ブロック。
      1) 🔎 不足側へのルールベース・アドバイス
      2) 🧩 ロールペア別 合計rDPS
      3) 個人別ミラーバー（バー長＝rDPS／バー上＝総ダメージ／斜線＝想定総ダメージ）
    範囲選択時はコントローラ（rangeStats）がこのファイルをそのまま描画して返すため、
    フェーズ表示と見た目が必ず一致する。JS側で作り直さないこと。

    @param array       $aList A側プレイヤー配列（summary['players']）
    @param array       $bList B側プレイヤー配列
    @param array|null  $adv   buildPhaseAdvice() の戻り値
    @param string      $scope 文言の主語（'このフェーズ' / 'この範囲'）
--}}
@php
    $scope = $scope ?? 'このフェーズ';
    $fmt = fn($n) => number_format((int) round($n));
    // 総ダメージを 8.59m / 842k 形式に
    $fmtAmt = function ($n) {
        if ($n >= 1000000) return number_format($n / 1000000, 2) . 'm';
        if ($n >= 1000) return number_format($n / 1000, 0) . 'k';
        return number_format((int) round($n));
    };
    $diffSpan = function ($d) use ($fmt) {
        $cls = $d > 0 ? 'text-rose-300' : ($d < 0 ? 'text-blue-300' : 'text-gray-400');
        return '<span class="' . $cls . ' font-mono">' . ($d > 0 ? '+' : '') . $fmt($d) . '</span>';
    };

    // バー共通スケール（窓内の最大rDPS。想定rDPSのゴースト延長も収まるように含める）
    $maxR = max(1,
        collect($aList)->max('rdps') ?? 0, collect($bList)->max('rdps') ?? 0,
        collect($aList)->max('potentialRdps') ?? 0, collect($bList)->max('potentialRdps') ?? 0);

    // A/Bのペアリング：①同ジョブ優先 → ②残りは同ロール → ③余りは単独
    $usedA = []; $usedB = []; $rows = [];
    foreach ($aList as $ai => $a) { foreach ($bList as $bi => $b) {
        if (isset($usedA[$ai]) || isset($usedB[$bi])) continue;
        if ($a['job'] === $b['job']) { $usedA[$ai] = $usedB[$bi] = true; $rows[] = ['a' => $a, 'b' => $b, 'role' => false]; break; }
    } }
    foreach ($aList as $ai => $a) { if (isset($usedA[$ai])) continue; foreach ($bList as $bi => $b) {
        if (isset($usedB[$bi])) continue;
        if ($a['role'] === $b['role']) { $usedA[$ai] = $usedB[$bi] = true; $rows[] = ['a' => $a, 'b' => $b, 'role' => true]; break; }
    } }
    foreach ($aList as $ai => $a) { if (!isset($usedA[$ai])) $rows[] = ['a' => $a, 'b' => null, 'role' => false]; }
    foreach ($bList as $bi => $b) { if (!isset($usedB[$bi])) $rows[] = ['a' => null, 'b' => $b, 'role' => false]; }
    usort($rows, fn($x, $y) => (($x['a']['roleOrder'] ?? $x['b']['roleOrder'] ?? 9) <=> ($y['a']['roleOrder'] ?? $y['b']['roleOrder'] ?? 9)));
@endphp
    {{-- DPS不足側へのルールベース・アドバイス --}}
        @if ($adv)
        <div class="advice mb-2 {{ $adv['low'] === 'A' ? 'low-a' : ($adv['low'] === 'B' ? 'low-b' : 'even') }}">
            @if ($adv['low'])
                <div class="text-sm font-bold {{ $adv['low'] === 'A' ? 'text-blue-300' : 'text-rose-300' }}">
                    🔎 {{ $scope }}の不足側：{{ $adv['low'] }} パーティ（-{{ $fmt($adv['gap']) }} rDPS ／ {{ number_format($adv['gapPct'], 1) }}%差）
                </div>
                <ul>
                    @foreach ($adv['tips'] as $tip)<li>{{ $tip }}</li>@endforeach
                </ul>
            @else
                <div class="text-sm text-gray-300">🤝 ほぼ互角（差 {{ number_format($adv['gapPct'], 1) }}%）。決定的なパーティDPS差はなし。</div>
            @endif
        </div>
    @endif

    {{-- ロールペア別（タンク/ヒーラー/近接/遠隔）の合計rDPS比較 --}}
    @php
        $roleGroups = ['tank' => 'タンク', 'healer' => 'ヒーラー', 'melee' => '近接', 'ranged' => '遠隔'];
        $grpOf = fn($p) => in_array($p['role'], ['ranged', 'caster'], true) ? 'ranged' : $p['role'];
        $roleAgg = [];
        foreach (['a' => $aList, 'b' => $bList] as $side => $lst) {
            foreach ($lst as $p) {
                $g = $grpOf($p);
                if (!isset($roleGroups[$g])) continue; // LB等は除外
                $roleAgg[$g][$side]['rdps'] = ($roleAgg[$g][$side]['rdps'] ?? 0) + $p['rdps'];
                $roleAgg[$g][$side]['pot'] = ($roleAgg[$g][$side]['pot'] ?? 0) + ($p['potentialRdps'] ?? $p['rdps']);
            }
        }
    @endphp
    <div class="text-xs font-bold text-gray-300 mb-1">🧩 ロールペア別 合計rDPS（タンク／ヒーラー／近接／遠隔=レンジ+キャスター）</div>
    <div class="role-strip">
        @foreach ($roleGroups as $g => $glabel)
            @php
                $ra = $roleAgg[$g]['a'] ?? null;
                $rb = $roleAgg[$g]['b'] ?? null;
                $rd = ($rb['rdps'] ?? 0) - ($ra['rdps'] ?? 0);
                $rp = ($rb['pot'] ?? 0) - ($ra['pot'] ?? 0);
            @endphp
            <div class="role-cell">
                <div class="text-xs text-gray-400 font-bold">{{ $glabel }}</div>
                <div class="text-xs"><span class="text-blue-300 font-mono">{{ $ra ? $fmt($ra['rdps']) : '—' }}</span><span class="text-gray-600"> vs </span><span class="text-rose-300 font-mono">{{ $rb ? $fmt($rb['rdps']) : '—' }}</span></div>
                @if ($ra && $rb)
                    <div class="text-xs" style="white-space: nowrap;"><span class="dlab">実際</span>{!! $diffSpan($rd) !!}　<span class="pot-diff" title="想定rDPS同士の差 B−A"><span class="dlab">想定</span>{!! $diffSpan($rp) !!}</span></div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- 個人別（ミラー型バー：A左/青・B右/赤、長い方が優勢。rDPS基準。ジョブ名はフルネーム両側表示） --}}
    <div class="cmp-head text-xs text-gray-400 pb-1 mb-1 border-b border-gray-700">
        <div class="text-right text-blue-300">A</div>
        <div class="text-gray-500" style="grid-column: span 2; text-align:center;">バー長＝rDPS（長い方が優勢）／バー上の数値＝総ダメージ／斜線内＝想定総ダメージ</div>
        <div class="text-left text-rose-300">B</div>
        <div class="text-right">差 B−A</div>
    </div>

    @foreach ($rows as $r)
        @php
            $a = $r['a']; $b = $r['b'];
            $d = ($b['rdps'] ?? 0) - ($a['rdps'] ?? 0);
            $aw = $a ? round($a['rdps'] / $maxR * 100) : 0;
            $bw = $b ? round($b['rdps'] / $maxR * 100) : 0;
            $hasBoth = $a && $b;
            // 想定rDPS（死亡・衰弱・ダメ低下が無い場合）のゴースト延長幅と表示判定（+0.5%超のみ）
            $apot = $a['potentialRdps'] ?? 0; $bpot = $b['potentialRdps'] ?? 0;
            $aHasPot = $a && $apot > $a['rdps'] * 1.005;
            $bHasPot = $b && $bpot > $b['rdps'] * 1.005;
            $agw = $aHasPot ? max(1, round(($apot - $a['rdps']) / $maxR * 100)) : 0;
            $bgw = $bHasPot ? max(1, round(($bpot - $b['rdps']) / $maxR * 100)) : 0;
            $potTitle = fn($p) => sprintf('死亡・衰弱・ダメージ低下が無い場合の想定：総ダメージ %s（実測 %s）／%s rDPS（+%s：死亡%s/衰弱%s/ダメ低下%s）',
                $fmtAmt($p['potentialTotal'] ?? $p['total']), $fmtAmt($p['total']),
                number_format(round($p['potentialRdps'])), number_format(round($p['potentialRdps'] - $p['rdps'])),
                number_format(round($p['lossDeathDps'] ?? 0)), number_format(round($p['lossWeakDps'] ?? 0)), number_format(round($p['lossDdDps'] ?? 0)));
        @endphp
        <div class="cmp-row">
            {{-- A：フルネーム(Active%)＋職アイコン / rDPS・DPS・aDPS --}}
            <div class="a-info">
                @if ($a)
                    <div class="nm">@if ($aHasPot)<span class="pot" title="{{ $potTitle($a) }}">▶{{ $fmt($apot) }}</span> @endif<span style="color: {{ $a['color'] }};">{{ $a['name'] }}</span>
                        <span class="text-gray-500" title="Active {{ $a['activePct'] }}%">({{ round($a['activePct']) }}%)</span>
                        @if ($a['iconFile'])<img class="jobicon ml-1" src="{{ asset('icons') }}/jobs/{{ $a['iconFile'] }}.png" onerror="this.style.display='none'">@endif
                    </div>
                    <div class="stat">
                        @if (!empty($a['potion']) || !empty($a['deaths']) || ($a['ddSec'] ?? 0) >= 1)
                            <span class="badges">@if (!empty($a['potion']))<img class="potion" src="{{ asset('icons') }}/abilities/{{ $a['potionIcon'] ?? '' }}" title="薬使用" onerror="this.style.display='none'">@endif @if (!empty($a['deaths']))<span class="skull" title="{{ $scope }}で死亡{{ $a['deaths'] }}回">💀@if ($a['deaths'] > 1)<span class="sec">×{{ $a['deaths'] }}</span>@endif</span>@endif @if (($a['ddSec'] ?? 0) >= 1)<span title="ダメージ低下デバフ {{ round($a['ddSec']) }}秒">@if (!empty($a['ddIcon']))<img class="potion" src="{{ asset('icons') }}/abilities/{{ $a['ddIcon'] }}" onerror="this.style.display='none'">@endif<span class="sec">{{ round($a['ddSec']) }}s</span></span>@endif</span>
                        @endif
                        <span class="sub">D{{ $fmt($a['dps']) }}/a{{ $fmt($a['adps']) }}</span>
                        <span class="rd {{ $d < 0 ? 'text-blue-300' : 'text-white' }}">{{ $fmt($a['rdps']) }}</span>
                    </div>
                @else
                    <span class="text-gray-600">—</span>
                @endif
            </div>
            <div class="bwrap a">@if ($agw > 0)@if ($agw < 14)<span class="gnum" title="{{ $potTitle($a) }}">{{ $fmtAmt($a['potentialTotal'] ?? 0) }}</span>@endif<div class="bar-ghost a" style="width: {{ $agw }}%" title="{{ $potTitle($a) }}">@if ($agw >= 14)▶{{ $fmtAmt($a['potentialTotal'] ?? 0) }}@endif</div>@endif<div class="bar-h a" style="width: {{ $aw }}%">@if ($a){{ $fmtAmt($a['total']) }}@endif</div></div>
            <div class="bwrap"><div class="bar-h b" style="width: {{ $bw }}%">@if ($b){{ $fmtAmt($b['total']) }}@endif</div>@if ($bgw > 0)<div class="bar-ghost b" style="width: {{ $bgw }}%" title="{{ $potTitle($b) }}">@if ($bgw >= 14){{ $fmtAmt($b['potentialTotal'] ?? 0) }}◀@endif</div>@if ($bgw < 14)<span class="gnum" title="{{ $potTitle($b) }}">{{ $fmtAmt($b['potentialTotal'] ?? 0) }}</span>@endif @endif</div>
            {{-- B：職アイコン＋フルネーム(Active%) / rDPS・DPS・aDPS --}}
            <div class="b-info">
                @if ($b)
                    <div class="nm">@if ($b['iconFile'])<img class="jobicon mr-1" src="{{ asset('icons') }}/jobs/{{ $b['iconFile'] }}.png" onerror="this.style.display='none'">@endif<span style="color: {{ $b['color'] }};">{{ $b['name'] }}</span>
                        <span class="text-gray-500" title="Active {{ $b['activePct'] }}%">({{ round($b['activePct']) }}%)</span>@if ($bHasPot) <span class="pot" title="{{ $potTitle($b) }}">▶{{ $fmt($bpot) }}</span>@endif
                    </div>
                    <div class="stat">
                        <span class="rd {{ $d > 0 ? 'text-rose-300' : 'text-white' }}">{{ $fmt($b['rdps']) }}</span>
                        <span class="sub">D{{ $fmt($b['dps']) }}/a{{ $fmt($b['adps']) }}</span>
                        @if (!empty($b['potion']) || !empty($b['deaths']) || ($b['ddSec'] ?? 0) >= 1)
                            <span class="badges">@if (!empty($b['potion']))<img class="potion" src="{{ asset('icons') }}/abilities/{{ $b['potionIcon'] ?? '' }}" title="薬使用" onerror="this.style.display='none'">@endif @if (!empty($b['deaths']))<span class="skull" title="{{ $scope }}で死亡{{ $b['deaths'] }}回">💀@if ($b['deaths'] > 1)<span class="sec">×{{ $b['deaths'] }}</span>@endif</span>@endif @if (($b['ddSec'] ?? 0) >= 1)<span title="ダメージ低下デバフ {{ round($b['ddSec']) }}秒">@if (!empty($b['ddIcon']))<img class="potion" src="{{ asset('icons') }}/abilities/{{ $b['ddIcon'] }}" onerror="this.style.display='none'">@endif<span class="sec">{{ round($b['ddSec']) }}s</span></span>@endif</span>
                        @endif
                    </div>
                @else
                    <span class="text-gray-600">—</span>
                @endif
            </div>
            {{-- 差（1行目＝実際差、2行目＝想定差。同ロール対戦の≈は同じ行に付ける） --}}
            <div class="text-right text-sm" @if (!empty($r['role'])) title="ロール比較" @endif>
                @if ($hasBoth)
                    <div style="white-space: nowrap;"><span class="dlab">実際</span>{!! $diffSpan($d) !!}@if (!empty($r['role']))<span class="text-amber-300 text-xs">≈</span>@endif</div>
                    @if ($aHasPot || $bHasPot)
                        @php $dp = ($b['potentialRdps'] ?? $b['rdps']) - ($a['potentialRdps'] ?? $a['rdps']); @endphp
                        <div title="想定rDPS（死亡・衰弱・ダメ低下が無い場合）同士の差 B−A" style="white-space: nowrap;"><span class="pot-diff"><span class="dlab">想定</span>{!! $diffSpan($dp) !!}</span></div>
                    @endif
                @else<span class="text-gray-600">—</span>@endif
            </div>
        </div>
    @endforeach
