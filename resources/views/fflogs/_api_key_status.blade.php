{{-- FFLogs API キーの設定状況（各ツールの入力画面に表示する）。キー未設定でもフォームは見せ、実行時に設定画面へ案内する --}}
@php($apiKey = app(\App\Support\FFLogsCredentialStore::class)->status())
@if ($apiKey['state'] === 'none')
    <div class="bg-yellow-900 border border-yellow-600 text-yellow-100 p-3 rounded mb-4 text-base">
        このツールを使うには、ご自身の FFLogs API キーの設定が必要です。
        <a href="{{ route('api_key.edit') }}" class="underline font-semibold text-yellow-200">API キーを設定する →</a>
    </div>
@else
    <div class="text-gray-300 mb-4 text-base">
        FFLogs API キー:
        @if ($apiKey['state'] === 'user')
            設定済み（Client ID {{ $apiKey['id_hint'] }}）
        @else
            開発用のサーバーのキーを使用中
        @endif
        <a href="{{ route('api_key.edit') }}" class="underline text-indigo-300 ml-1">変更</a>
    </div>
@endif
