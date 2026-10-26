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
        <div class="en-head text-base text-secondary legacy-pb-1 legacy-mb-1 legacy-border-b border-secondary">
            <div>敵（ボス／雑魚）</div>
            <div class="en-av text-end text-blue">A 被ダメ</div>
            <div class="en-note text-secondary" style="grid-column: span 2; text-align:center;">バー長＝各敵に入った総ダメージ</div>
            <div class="en-bv text-start text-red">B 被ダメ</div>
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
                <div class="en-name {{ $isBoss ? 'text-yellow fw-bold' : 'text-body' }}" title="{{ $nm }}">{{ $nm }}</div>
                <div class="en-av text-end font-monospace text-base {{ $av >= $bv ? 'text-blue' : 'text-secondary' }}">{{ $av > 0 ? $fmtAmt($av) : '—' }}</div>
                <div class="bwrap a"><div class="bar-h a" style="width: {{ $aw }}%"></div></div>
                <div class="bwrap b"><div class="bar-h b" style="width: {{ $bw }}%"></div></div>
                <div class="en-bv text-start font-monospace text-base {{ $bv >= $av ? 'text-red' : 'text-secondary' }}">{{ $bv > 0 ? $fmtAmt($bv) : '—' }}</div>
            </div>
        @endforeach
    </div>
@endif
