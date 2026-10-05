<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FFLogs API キーの設定 - FFLogs ツール</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>

<body class="bg-gray-900 text-gray-100 min-h-screen flex flex-col items-center justify-start py-10 px-4">
    <div class="w-full max-w-2xl p-8 bg-gray-800 rounded-lg shadow-xl text-base">
        <h1 class="text-2xl font-bold mb-2 text-indigo-400">FFLogs API キーの設定</h1>
        <p class="text-gray-300 mb-6">
            このツールは FFLogs の API を使ってログを取得します。ご自身の FFLogs アカウントで作成した API キー
            （Client ID と Client Secret）を設定すると使えるようになります。
        </p>

        @if (session('status'))
            <div class="bg-green-700 text-white p-3 rounded mb-4">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="bg-red-600 text-white p-3 rounded mb-4">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="bg-red-600 text-white p-3 rounded mb-4">
                @foreach ($errors->all() as $message)
                    <div>{{ $message }}</div>
                @endforeach
            </div>
        @endif

        {{-- 現在の状態 --}}
        <div class="mb-6 p-4 rounded border border-gray-600 bg-gray-900">
            @if ($status['state'] === 'user')
                <div>現在: <span class="text-green-400 font-semibold">設定済み</span>（Client ID {{ $status['id_hint'] }}）</div>
                <form action="{{ route('api_key.destroy') }}" method="POST" class="mt-3">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded border border-gray-500">
                        このブラウザから削除する
                    </button>
                </form>
            @elseif ($status['state'] === 'server')
                <div>現在: <span class="text-yellow-300 font-semibold">開発用のサーバーのキーを使用中</span>（Client ID {{ $status['id_hint'] }}）</div>
                <div class="text-gray-400 mt-1">公開環境ではこの状態にならず、各自のキーの設定が必要です。</div>
            @else
                <div>現在: <span class="text-yellow-300 font-semibold">未設定</span></div>
            @endif
        </div>

        <form action="{{ route('api_key.update') }}" method="POST" class="space-y-4" autocomplete="off">
            @csrf
            <div>
                <label for="client_id" class="block font-medium text-gray-200">Client ID</label>
                <input type="text" name="client_id" id="client_id" required value="{{ old('client_id') }}"
                    spellcheck="false" autocapitalize="off"
                    class="mt-1 block w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-md text-white font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label for="client_secret" class="block font-medium text-gray-200">Client Secret</label>
                <input type="password" name="client_secret" id="client_secret" required autocomplete="new-password"
                    spellcheck="false" autocapitalize="off"
                    class="mt-1 block w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-md text-white font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500">
            </div>
            <label class="flex items-start gap-2">
                <input type="checkbox" name="remember" value="1" class="mt-1" @checked(old('remember'))>
                <span>このブラウザに 30 日間保存する<br><span class="text-gray-400">チェックしない場合は、ブラウザを閉じると消えます。共用のパソコンではチェックしないでください。</span></span>
            </label>
            <button type="submit"
                class="w-full py-2 px-4 bg-indigo-600 hover:bg-indigo-700 rounded-md font-semibold text-white transition duration-200">
                FFLogs で確認して保存
            </button>
        </form>

        <h2 class="text-xl font-bold mt-8 mb-2 text-indigo-300">キーの作り方</h2>
        <ol class="list-decimal ml-6 space-y-1 text-gray-200">
            <li>FFLogs にログインし、<a href="https://ja.fflogs.com/api/clients/" target="_blank" rel="noopener noreferrer" class="text-indigo-400 underline">API クライアントの管理画面</a>を開く</li>
            <li>新しいクライアントを作成する（名前は自由。リダイレクト URL はこのツールでは使わないので、任意の URL で構いません）</li>
            <li>表示された Client ID と Client Secret を上の欄に貼り付ける</li>
        </ol>
        <p class="text-gray-400 mt-2">画面の表記は FFLogs 側の変更で異なる場合があります。</p>

        <h2 class="text-xl font-bold mt-8 mb-2 text-indigo-300">キーの扱い</h2>
        <ul class="list-disc ml-6 space-y-1 text-gray-200">
            <li>キーは<strong>このブラウザの Cookie に暗号化して保存</strong>し、サーバーには保存しません。JavaScript からは読み取れない設定にしています。</li>
            <li>解析などを実行したときに、FFLogs からアクセストークンを受け取るためだけに使います。受け取ったアクセストークンは、高速化のため最大 50 分サーバーに一時保存します。</li>
            <li>API の利用量は、設定したキーの上限（FFLogs の 1 時間あたりのポイント）から消費されます。</li>
            <li>不要になったら「このブラウザから削除する」を押すか、FFLogs 側でクライアントを削除してください。</li>
        </ul>

        <div class="mt-8 pt-4 border-t border-gray-700 text-center">
            <a href="{{ url('/') }}" class="text-gray-400 hover:text-indigo-400 underline">← 目次へ</a>
        </div>
    </div>
    @include('_copyright')
</body>

</html>
