<!DOCTYPE html>
<html lang="ja" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FFLogs API キーの設定 - FFLogs ツール</title>
    @include('_ui_head')
</head>

<body class="logs-app">
    @include('_tool_nav')
    <div class="container-xl py-4">
        <h1 class="fs-1 fw-bold legacy-mb-2 text-primary">FFLogs API キーの設定</h1>
        <p class="text-body legacy-mb-6">
            このツールは FFLogs の API を使ってログを取得します。ご自身の FFLogs アカウントで作成した API キー
            （Client ID と Client Secret）を設定すると使えるようになります。
        </p>

        @if (session('status'))
            <div class="bg-green text-white legacy-p-3 rounded legacy-mb-4">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="bg-red text-white legacy-p-3 rounded legacy-mb-4">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="bg-red text-white legacy-p-3 rounded legacy-mb-4">
                @foreach ($errors->all() as $message)
                    <div>{{ $message }}</div>
                @endforeach
            </div>
        @endif

        {{-- 現在の状態 --}}
        <div class="legacy-mb-6 legacy-p-4 rounded border border-secondary bg-body">
            @if ($status['state'] === 'user')
                <div>現在: <span class="text-green fw-semibold">設定済み</span>（Client ID {{ $status['id_hint'] }}）</div>
                <form action="{{ route('api_key.destroy') }}" method="POST" class="card card-body app-form mb-3">
                    @csrf
                    <button type="submit" class="btn legacy-px-4 legacy-py-2 bg-body-tertiary  rounded border border-secondary">
                        このブラウザから削除する
                    </button>
                </form>
            @elseif ($status['state'] === 'server')
                <div>現在: <span class="text-yellow fw-semibold">開発用のサーバーのキーを使用中</span>（Client ID {{ $status['id_hint'] }}）</div>
                <div class="text-secondary legacy-mt-1">公開環境ではこの状態にならず、各自のキーの設定が必要です。</div>
            @else
                <div>現在: <span class="text-yellow fw-semibold">未設定</span></div>
            @endif
        </div>

        <form action="{{ route('api_key.update') }}" method="POST" class="card card-body app-form mb-3" autocomplete="off">
            @csrf
            <div>
                <label for="client_id" class="legacy-block fw-medium text-body">Client ID</label>
                <input type="text" name="client_id" id="client_id" required value="{{ old('client_id') }}"
                    spellcheck="false" autocapitalize="off"
                    class="form-control legacy-mt-1 legacy-block legacy-w-full legacy-px-3 legacy-py-2 bg-body-tertiary border border-secondary rounded text-white font-monospace   ">
            </div>
            <div>
                <label for="client_secret" class="legacy-block fw-medium text-body">Client Secret</label>
                <input type="password" name="client_secret" id="client_secret" required autocomplete="new-password"
                    spellcheck="false" autocapitalize="off"
                    class="form-control legacy-mt-1 legacy-block legacy-w-full legacy-px-3 legacy-py-2 bg-body-tertiary border border-secondary rounded text-white font-monospace   ">
            </div>
            <label class="legacy-flex legacy-items-start legacy-gap-2">
                <input type="checkbox" name="remember" value="1" class="form-check-input legacy-mt-1" @checked(old('remember'))>
                <span>このブラウザに 30 日間保存する<br><span class="text-secondary">チェックしない場合は、ブラウザを閉じると消えます。共用のパソコンではチェックしないでください。</span></span>
            </label>
            <button type="submit"
                class="btn btn-primary legacy-w-full legacy-py-2 legacy-px-4 bg-primary  rounded fw-semibold text-white  ">
                FFLogs で確認して保存
            </button>
        </form>

        <h2 class="fs-2 fw-bold legacy-mt-8 legacy-mb-2 text-primary">キーの作り方</h2>
        <ol class="legacy-list-decimal legacy-ml-6 legacy-space-y-1 text-body">
            <li>FFLogs にログインし、<a href="https://ja.fflogs.com/api/clients/" target="_blank" rel="noopener noreferrer" class="text-primary text-decoration-underline">API クライアントの管理画面</a>を開く</li>
            <li>新しいクライアントを作成する（名前は自由。リダイレクト URL はこのツールでは使わないので、任意の URL で構いません）</li>
            <li>表示された Client ID と Client Secret を上の欄に貼り付ける</li>
        </ol>
        <p class="text-secondary legacy-mt-2">画面の表記は FFLogs 側の変更で異なる場合があります。</p>

        <h2 class="fs-2 fw-bold legacy-mt-8 legacy-mb-2 text-primary">キーの扱い</h2>
        <ul class="legacy-list-disc legacy-ml-6 legacy-space-y-1 text-body">
            <li>キーは<strong>このブラウザの Cookie に暗号化して保存</strong>し、サーバーには保存しません。JavaScript からは読み取れない設定にしています。</li>
            <li>解析などを実行したときに、FFLogs からアクセストークンを受け取るためだけに使います。受け取ったアクセストークンは、高速化のため最大 50 分サーバーに一時保存します。</li>
            <li>API の利用量は、設定したキーの上限（FFLogs の 1 時間あたりのポイント）から消費されます。</li>
            <li>不要になったら「このブラウザから削除する」を押すか、FFLogs 側でクライアントを削除してください。</li>
        </ul>

        <div class="legacy-mt-8 legacy-pt-4 legacy-border-t border-secondary text-center">
            <a href="{{ url('/') }}" class="text-secondary  text-decoration-underline">← 目次へ</a>
        </div>
    </div>
    @include('_copyright')
</body>

</html>
