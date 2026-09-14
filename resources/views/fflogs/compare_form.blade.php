<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>A/B比較 - FFLogs</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>

<body class="bg-gray-900 text-gray-100 min-h-screen flex items-center justify-center">
    <div class="w-full max-w-xl p-8 bg-gray-800 rounded-lg shadow-xl">
        <h1 class="text-2xl font-bold mb-2 text-center text-indigo-400">パーティ A/B 火力・ローテ比較</h1>
        <p class="text-center text-gray-400 mb-6" style="font-size:1rem;">同じボスの2ログを、フェーズ別・分別に比較します。</p>

        @if (session('error'))
            <div class="bg-red-500 text-white p-3 rounded mb-4" style="font-size:1rem;">
                {{ session('error') }}
            </div>
        @endif

        <form action="{{ route('compare') }}" method="POST" class="space-y-5">
            @csrf
            <div>
                <label for="url_a" class="block font-medium text-blue-300" style="font-size:1.05rem;">パーティ A の URL</label>
                <input type="text" name="url_a" id="url_a" required placeholder="https://ja.fflogs.com/reports/XXXX?fight=11"
                    class="mt-1 block w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-md text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    style="font-size:1rem;">
            </div>
            <div>
                <label for="url_b" class="block font-medium text-rose-300" style="font-size:1.05rem;">パーティ B の URL</label>
                <input type="text" name="url_b" id="url_b" required placeholder="https://ja.fflogs.com/reports/YYYY?fight=9"
                    class="mt-1 block w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-md text-white focus:outline-none focus:ring-2 focus:ring-rose-500"
                    style="font-size:1rem;">
            </div>
            <p class="text-xs text-gray-400" style="font-size:0.95rem;">※ それぞれ <code>?fight=◯</code> 付きのURLを貼ってください。比較は同じボス同士が前提です。</p>

            <button type="submit"
                class="w-full py-2 px-4 bg-indigo-600 hover:bg-indigo-700 rounded-md font-semibold text-white transition duration-200" style="font-size:1.05rem;">
                比較する
            </button>
        </form>

        <div class="mt-6 text-center">
            <a href="{{ url('/') }}" class="text-gray-400 hover:text-indigo-400 underline" style="font-size:1rem;">← 目次へ</a>
        </div>
    </div>
</body>

</html>
