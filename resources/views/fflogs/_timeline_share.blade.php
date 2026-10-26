<div class="modal fade" id="timeline-share" tabindex="-1" aria-labelledby="timeline-share-title" aria-hidden="true"
    data-share-endpoint="{{ route('timeline-shares.store') }}" data-share-draft="{{ $shareDraft ?? '' }}">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title" id="timeline-share-title">軽減・バリア結果をURLで共有</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button></div>
        <div class="modal-body">
            <p>相手のAPIキーは不要です。共有時点の解析結果を表示します。</p>
            <div class="alert alert-info">URLを知っている人は、プレイヤー名・シナジー・死因などの詳細も閲覧できます。APIキーは共有されません。</div>
            @csrf
            <p>有効期間：作成から30日。1つのブラウザで有効な共有は{{ \App\Services\TimelineShareStore::MAX_PER_OWNER }}件まで。作成したブラウザで共有URLを開くと、共有を停止できます（ブラウザのセッションが有効な間）。</p>
            <div id="timeline-share-error" class="alert alert-danger" role="alert" hidden></div>
            <div id="timeline-share-result" @if (!($isShared ?? false)) hidden @endif>
                <label class="form-label" for="timeline-share-url">共有URL</label>
                <div class="input-group"><input class="form-control" id="timeline-share-url" readonly value="{{ ($isShared ?? false) ? route('timeline-shares.show', $shareId) : '' }}"><button type="button" class="btn" id="timeline-share-copy">コピー</button></div>
                <p id="timeline-share-expiry" class="text-secondary mt-2">@if ($isShared ?? false)有効期限：{{ \Illuminate\Support\Carbon::parse($shareExpiresAt)->timezone('Asia/Tokyo')->format('Y/m/d H:i') }}（日本時間）@endif</p>
                <p id="timeline-share-status" class="text-secondary" role="status" aria-live="polite"></p>
                <a class="btn" id="timeline-share-open" href="{{ ($isShared ?? false) ? route('timeline-shares.show', $shareId) : '#' }}" target="_blank" rel="noopener noreferrer">共有画面を開く</a>
            </div>
            @if (!($isShared ?? false))
                <button type="button" class="btn btn-primary" id="timeline-share-create">共有URLを作成する</button>
            @endif
        </div>
        @if ($canDeleteShare ?? false)
        <div class="modal-footer"><form method="POST" action="{{ route('timeline-shares.destroy', $shareId) }}" onsubmit="return confirm('このURLからの閲覧を停止しますか？')">@csrf<button class="btn btn-outline-danger" type="submit">共有を停止する</button></form></div>
        @endif
    </div></div>
</div>
<script src="{{ asset('js/timeline-share.js') }}?v={{ filemtime(public_path('js/timeline-share.js')) }}" defer></script>
