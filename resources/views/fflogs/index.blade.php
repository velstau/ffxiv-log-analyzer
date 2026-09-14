<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FFLogs Timeline Viewer</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>

<body class="bg-gray-900 text-gray-100 min-h-screen flex items-center justify-center">
    <div class="w-full max-w-lg p-8 bg-gray-800 rounded-lg shadow-xl">
        <h1 class="text-3xl font-bold mb-6 text-center text-indigo-400">FFLogs Timeline Viewer</h1>

        @if (session('error'))
            <div class="bg-red-500 text-white p-3 rounded mb-4">
                {{ session('error') }}
            </div>
        @endif

        <form action="{{ route('analyze') }}" method="POST" class="space-y-4">
            @csrf
            <div>
                <label for="url" class="block text-sm font-medium text-gray-300">FFLogs Report URL</label>
                <input type="text" name="url" id="url" required
                    placeholder="https://ja.fflogs.com/reports/..."
                    class="mt-1 block w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-md text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <p class="mt-1 text-xs text-gray-400">
                    例: https://ja.fflogs.com/reports/XXXXXXXXXXXXXXXX?fight=11
                </p>
            </div>

            <button type="submit"
                class="w-full py-2 px-4 bg-indigo-600 hover:bg-indigo-700 rounded-md font-semibold text-white transition duration-200">
                Analyze
            </button>
        </form>

        <div class="mt-6 pt-4 border-t border-gray-700 text-center">
            <a href="{{ url('/') }}" class="text-gray-400 hover:text-indigo-400 underline" style="font-size:1rem;">← 目次へ</a>
        </div>
    </div>
</body>

</html>
