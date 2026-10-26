<!DOCTYPE html>
<html lang="ja" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FFLogs Timeline Viewer</title>
    @include('_ui_head')
</head>

<body class="logs-app">
    @include('_tool_nav')
    <div class="container-xl py-4">
        <h1 class="fs-1 fw-bold legacy-mb-6 text-center text-primary">FFLogs Timeline Viewer</h1>

        @include('fflogs._api_key_status')

        @if (session('error'))
            <div class="bg-red text-white legacy-p-3 rounded legacy-mb-4">
                {{ session('error') }}
            </div>
        @endif

        <form action="{{ route('analyze') }}" method="POST" class="card card-body app-form mb-3">
            @csrf
            <div>
                <label for="url" class="legacy-block text-base fw-medium text-body">FFLogs Report URL</label>
                <input type="text" name="url" id="url" required
                    placeholder="https://ja.fflogs.com/reports/..."
                    class="form-control legacy-mt-1 legacy-block legacy-w-full legacy-px-3 legacy-py-2 bg-body-tertiary border border-secondary rounded text-white   ">
                <p class="legacy-mt-1 text-base text-secondary">
                    例: https://ja.fflogs.com/reports/XXXXXXXXXXXXXXXX?fight=11
                </p>
            </div>

            <button type="submit"
                class="btn btn-primary legacy-w-full legacy-py-2 legacy-px-4 bg-primary  rounded fw-semibold text-white  ">
                Analyze
            </button>
        </form>

        <div class="legacy-mt-6 legacy-pt-4 legacy-border-t border-secondary text-center">
            <a href="{{ url('/') }}" class="text-secondary  text-decoration-underline" style="font-size:1rem;">← 目次へ</a>
        </div>
    </div>
    @include('_copyright')
</body>

</html>
