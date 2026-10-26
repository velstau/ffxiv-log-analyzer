{{-- FFLogs API キーの設定状況（各ツールの入力画面に表示する）。キー未設定でもフォームは見せ、実行時に設定画面へ案内する --}}
@php($apiKey = app(\App\Support\FFLogsCredentialStore::class)->status())
@if ($apiKey['state'] === 'none')
    <div class="bg-yellow-lt border border-yellow text-yellow legacy-p-3 rounded legacy-mb-4 text-base">
        このツールを使うには、ご自身の FFLogs API キーの設定が必要です。
        <a href="{{ route('api_key.edit') }}" class="text-decoration-underline fw-semibold text-yellow">API キーを設定する →</a>
    </div>
@else
    <div class="text-body legacy-mb-4 text-base">
        FFLogs API キー:
        @if ($apiKey['state'] === 'user')
            設定済み（Client ID {{ $apiKey['id_hint'] }}）
        @else
            開発用のサーバーのキーを使用中
        @endif
        <a href="{{ route('api_key.edit') }}" class="text-decoration-underline text-primary legacy-ml-1">変更</a>
    </div>
@endif
