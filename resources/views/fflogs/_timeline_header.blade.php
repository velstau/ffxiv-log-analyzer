<header class="timeline-toolbar border-bottom">
    <div class="analysis-brandline border-bottom">
        <a class="d-inline-flex align-items-center flex-wrap gap-2 text-decoration-none" href="{{ route('home') }}" aria-label="FFLogs ツールのTOPへ">
            <span class="fw-bold text-body">FFLogs ツール</span><span class="text-secondary">／ ツールTOP</span>
        </a>
        <a class="btn" href="{{ route('mitigation.form') }}">新しい解析</a>
    </div>
    <div class="analysis-topline">
        <div class="analysis-summary">
            <div class="text-secondary mb-1">軽減・バリア解析結果</div>
            <h1 class="h3 m-0">{{ $fight['name'] }}</h1>
            <div class="d-flex align-items-center flex-wrap gap-3 mt-2">
                <span class="text-secondary">戦闘時間 <strong class="text-body">{{ gmdate('i:s', ($fight['endTime'] - $fight['startTime']) / 1000) }}</strong></span>
                @if ($isShared ?? false)
                    <span class="badge bg-blue-lt" title="有効期限：{{ $shareExpiresAt }}">共有結果（APIキー不要）</span>
                @elseif ($cachedResult ?? false)
                    <span class="badge bg-secondary-lt" title="解析結果は2時間一時保存されます。再読み込みではAPIを呼び出しません。">結果を一時保存中（2時間）</span>
                @endif
            </div>
        </div>
        <div class="analysis-actions">
            <div class="analysis-action-group" role="group" aria-label="表示操作">
                <span class="text-secondary">表示</span>
                <button type="button" id="toggle-players" class="btn btn-outline-primary active" aria-pressed="true" onclick="togglePlayers()" title="プレイヤー列の表示切替"><x-ui-icon name="users" />プレイヤー列</button>
                <button type="button" class="btn" onclick="openActionFilterModal()" title="技の表示を絞り込む"><x-ui-icon name="filter" />技フィルター</button>
                <button type="button" class="btn" data-bs-toggle="modal" data-bs-target="#timeline-legend"><x-ui-icon name="list-details" />凡例</button>
            </div>
            <div class="analysis-action-group" role="group" aria-label="保存と共有">
                <span class="text-secondary">保存・共有</span>
                <button type="button" class="btn btn-primary" onclick="downloadHojoringXML()" title="タイムラインをXMLで保存"><x-ui-icon name="files" />XML出力</button>
                @if (($shareDraft ?? false) || ($isShared ?? false))
                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#timeline-share">{{ ($isShared ?? false) ? '共有URL・管理' : 'URLで共有' }}</button>
                @endif
            </div>
            @if ($sharePreparationFailed ?? false)
                <span class="text-warning" role="status">共有の準備に失敗しました。結果を一時保存できないため、再読み込み時は再解析が必要です。</span>
            @endif
        </div>
    </div>
    <div class="analysis-tabline">
        <div class="nav nav-tabs" role="tablist" aria-label="解析ビュー">
            <button type="button" id="tab-btn-mitigation" onclick="switchView('mitigation')" class="nav-link active" role="tab" aria-selected="true" aria-controls="view-mitigation">🛡️ 軽減・バリア</button>
            <button type="button" id="tab-btn-synergy" onclick="switchView('synergy')" class="nav-link" role="tab" aria-selected="false" aria-controls="view-synergy">⚔️ シナジー・バフ</button>
            @php $totalDeaths = array_sum(array_map(fn($g) => $g['victim_count'], $deaths)); @endphp
            <button type="button" id="tab-btn-death" onclick="switchView('death')" class="nav-link" role="tab" aria-selected="false" aria-controls="view-death">💀 デスチェッカー @if ($totalDeaths)<span class="text-red ms-1">({{ $totalDeaths }})</span>@endif</button>
        </div>
        <div class="timeline-state-key text-secondary"><span><i class="mit mit-on" aria-hidden="true"></i>軽減適用・効果中</span><span><i class="mit mit-cd" aria-hidden="true"></i>リキャスト中</span><span><i class="mit mit-x" aria-hidden="true"></i>バリア破壊</span></div>
    </div>
</header>
