<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FFLogs ツール</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>

<body class="bg-gray-900 text-gray-100 min-h-screen flex flex-col items-center justify-center py-10">
    <div class="w-full max-w-3xl px-6">
        <h1 class="text-3xl font-bold text-center text-indigo-400">FFLogs ツール</h1>
        <p class="text-center text-gray-400 mt-2 mb-8" style="font-size:1rem;">FFLogs のログを読み解くための道具箱です。</p>
        @include('fflogs._api_key_status')

        <div class="space-y-4">
            <a href="{{ route('mitigation.form') }}"
                class="block p-6 bg-gray-800 rounded-lg shadow-xl border border-gray-700 hover:border-indigo-500 transition duration-200">
                <div class="flex items-baseline">
                    <span class="text-2xl mr-3">🛡</span>
                    <span class="text-xl font-bold text-indigo-300">軽減</span>
                </div>
                <p class="mt-2 text-gray-400" style="font-size:1rem;">
                    1つのログをタイムライン化し、ボスの攻撃ごとに「誰が何を撃って何%軽減できていたか」を並べて確認します。
                </p>
            </a>

            <a href="{{ route('compare.form') }}"
                class="block p-6 bg-gray-800 rounded-lg shadow-xl border border-gray-700 hover:border-rose-500 transition duration-200">
                <div class="flex items-baseline">
                    <span class="text-2xl mr-3">⚔</span>
                    <span class="text-xl font-bold text-rose-300">DPS比較</span>
                </div>
                <p class="mt-2 text-gray-400" style="font-size:1rem;">
                    同じボスの2つのログを A/B で突き合わせ、フェーズ別・分別に火力とローテーションの差を出します。
                </p>
            </a>

            <a href="{{ route('party_search.form') }}"
                class="block p-6 bg-gray-800 rounded-lg shadow-xl border border-gray-700 hover:border-emerald-500 transition duration-200">
                <div class="flex items-baseline">
                    <span class="text-2xl mr-3">🔍</span>
                    <span class="text-xl font-bold text-emerald-300">PT検索</span>
                </div>
                <p class="mt-2 text-gray-400" style="font-size:1rem;">
                    ジョブ構成を指定して、それと一致する討伐ログをランキングから探します。
                    サイトのUIではロール人数までしか絞れないところを、ジョブ単位で照合します。
                </p>
            </a>
        </div>
    </div>
    @include('_copyright')
</body>

</html>
