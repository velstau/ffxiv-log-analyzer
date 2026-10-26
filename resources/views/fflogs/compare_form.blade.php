<!DOCTYPE html>
<html lang="ja" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>A/B比較 - FFLogs</title>
    @include('_ui_head')
</head>

<body class="logs-app">
    @include('_tool_nav')
    <div class="container-xl py-4">
        <h1 class="fs-1 fw-bold legacy-mb-2 text-center text-primary">パーティ A/B 火力・ローテ比較</h1>
        <p class="text-center text-secondary legacy-mb-6" style="font-size:1rem;">同じボスの2ログを、フェーズ別・分別に比較します。</p>

        @include('fflogs._api_key_status')

        @if (session('error'))
            <div class="bg-red text-white legacy-p-3 rounded legacy-mb-4" style="font-size:1rem;">
                {{ session('error') }}
            </div>
        @endif

        <form action="{{ route('compare') }}" method="POST" class="card card-body app-form mb-3">
            @csrf
            <div>
                <label for="url_a" class="legacy-block fw-medium text-blue" style="font-size:1.05rem;">パーティ A の URL</label>
                <input type="text" name="url_a" id="url_a" required placeholder="https://ja.fflogs.com/reports/XXXX?fight=11"
                    class="form-control legacy-mt-1 legacy-block legacy-w-full legacy-px-3 legacy-py-2 bg-body-tertiary border border-secondary rounded text-white   "
                    style="font-size:1rem;">
            </div>
            <div>
                <label for="url_b" class="legacy-block fw-medium text-red" style="font-size:1.05rem;">パーティ B の URL</label>
                <input type="text" name="url_b" id="url_b" required placeholder="https://ja.fflogs.com/reports/YYYY?fight=9"
                    class="form-control legacy-mt-1 legacy-block legacy-w-full legacy-px-3 legacy-py-2 bg-body-tertiary border border-secondary rounded text-white   "
                    style="font-size:1rem;">
            </div>
            <p class="text-base text-secondary" style="font-size:1rem;">※ それぞれ <code>?fight=◯</code> 付きのURLを貼ってください。比較は同じボス同士が前提です。</p>
            <label class="form-check" style="font-size:1rem;">
                <input class="form-check-input" type="checkbox" name="hide_names" value="1" id="hide_names">
                <span class="form-check-label">プレイヤー名を表示しない（「SCH(A)」のように表示。共有URLにも実名を残しません）</span>
            </label>
            <script>
                // 前回の選択を覚えておく（このブラウザだけ）
                (function () {
                    const box = document.getElementById('hide_names');
                    try { box.checked = localStorage.getItem('compare.hideNames') === '1'; } catch (e) {}
                    box.addEventListener('change', () => { try { localStorage.setItem('compare.hideNames', box.checked ? '1' : '0'); } catch (e) {} });
                })();
            </script>

            <button type="submit"
                class="btn btn-primary legacy-w-full legacy-py-2 legacy-px-4 bg-primary  rounded fw-semibold text-white  " style="font-size:1.05rem;">
                比較する
            </button>
        </form>

        <div class="legacy-mt-6 text-center">
            <a href="{{ url('/') }}" class="text-secondary  text-decoration-underline" style="font-size:1rem;">← 目次へ</a>
        </div>
    </div>
    @include('_copyright')
</body>

</html>
