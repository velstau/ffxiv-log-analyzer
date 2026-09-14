{{-- 敵別（ボス別）ダメージのA/Bミラー表示。呼び出し側で $aEnemies / $bEnemies を渡す。
     各要素: ['name'=>, 'total'=>, 'type'=>, 'icon'=>]。$fmtAmt / $fmt は親スコープの整形クロージャ。 --}}
@php
    $aEn = collect($aEnemies ?? [])->keyBy('name');
    $bEn = collect($bEnemies ?? [])->keyBy('name');
    $enNames = collect(array_merge(array_keys($aEn->all()), array_keys($bEn->all())))
        ->unique()
        ->sortByDesc(fn($nm) => max($aEn[$nm]['total'] ?? 0, $bEn[$nm]['total'] ?? 0))
        ->values();
    $enMax = max(1, collect($aEnemies ?? [])->max('total') ?? 0, collect($bEnemies ?? [])->max('total') ?? 0);
@endphp
@if ($enNames->isNotEmpty())
    <div class="en-block">
        <div class="en-head text-xs text-gray-400 pb-1 mb-1 border-b border-gray-700">
            <div>敵（ボス／雑魚）</div>
            <div class="text-right text-blue-300">A 被ダメ</div>
            <div class="text-gray-500" style="grid-column: span 2; text-align:center;">バー長＝そのフェーズで各敵に入った総ダメージ</div>
            <div class="text-left text-rose-300">B 被ダメ</div>
        </div>
        @foreach ($enNames as $nm)
            @php
                $av = (float) ($aEn[$nm]['total'] ?? 0);
                $bv = (float) ($bEn[$nm]['total'] ?? 0);
                $aw = round($av / $enMax * 100);
                $bw = round($bv / $enMax * 100);
                $isBoss = in_array(($aEn[$nm]['type'] ?? $bEn[$nm]['type'] ?? ''), ['Boss', 'boss'], true);
            @endphp
            <div class="en-row">
                <div class="en-name {{ $isBoss ? 'text-amber-200 font-bold' : 'text-gray-300' }}" title="{{ $nm }}">{{ $nm }}</div>
                <div class="text-right font-mono text-xs {{ $av >= $bv ? 'text-blue-200' : 'text-gray-500' }}">{{ $av > 0 ? $fmtAmt($av) : '—' }}</div>
                <div class="bwrap a"><div class="bar-h a" style="width: {{ $aw }}%"></div></div>
                <div class="bwrap"><div class="bar-h b" style="width: {{ $bw }}%"></div></div>
                <div class="text-left font-mono text-xs {{ $bv >= $av ? 'text-rose-200' : 'text-gray-500' }}">{{ $bv > 0 ? $fmtAmt($bv) : '—' }}</div>
            </div>
        @endforeach
    </div>
@endif
