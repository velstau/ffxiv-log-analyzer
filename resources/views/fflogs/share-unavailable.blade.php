<!DOCTYPE html>
<html lang="ja" data-bs-theme="dark"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex,nofollow"><title>{{ ($temporaryResult ?? false) ? '解析結果' : '共有結果' }} - FFLogs ツール</title>@include('_ui_head')</head>
<body class="logs-app">@include('_tool_nav')<main class="container-xl py-4"><section class="card"><div class="card-body">
<h1 class="card-title">{{ ($temporaryResult ?? false) ? '解析結果を表示できません' : (($deleted ?? false) ? '共有を停止しました' : '共有結果を表示できません') }}</h1>
<p>{{ ($temporaryResult ?? false) ? '一時保存した解析結果が期限切れか、解析したブラウザのセッションがありません。もう一度解析してください。' : (($deleted ?? false) ? 'この共有URLからは、結果を閲覧できなくなりました。' : 'URLが正しくないか、有効期限が終了したか、作成者によって共有が停止されています。') }}</p>
<a class="btn btn-primary" href="{{ route('mitigation.form') }}">軽減・バリア解析へ</a>
</div></section></main>@include('_copyright')</body></html>
