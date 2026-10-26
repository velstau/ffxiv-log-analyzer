<!DOCTYPE html><html lang="ja" data-bs-theme="dark"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>FFLogs ツール</title>@include('_ui_head')</head>
<body class="logs-app">@include('_tool_nav')<main class="container-xl py-4"><div class="page-header mb-4"><div class="page-pretitle">FFLOGS TOOLS</div><h1 class="page-title">FFLogs ツール</h1><p class="text-secondary mt-2">FFLogs のログを読み解くための道具箱です。</p></div>@include('fflogs._api_key_status')
<div class="row g-3">
@foreach ([['🛡','軽減','mitigation.form','1つのログをタイムライン化し、ボスの攻撃ごとに「誰が何を撃って何%軽減できていたか」を並べて確認します。','blue'],['⚔','DPS比較','compare.form','同じボスの2つのログをA/Bで突き合わせ、フェーズ別・分別に火力とローテーションの差を出します。','red'],['🔍','PT検索','party_search.form','ジョブ構成を指定して、それと一致する討伐ログをランキングから探します。サイトのUIではロール人数までしか絞れないところを、ジョブ単位で照合します。','green']] as [$icon,$label,$route,$description,$color])
<div class="col-md-4"><a href="{{ route($route) }}" class="card h-100 text-reset text-decoration-none"><div class="card-body d-flex flex-column"><span class="avatar tool-icon bg-{{ $color }}-lt mb-3" aria-hidden="true">{{ $icon }}</span><h2 class="card-title">{{ $label }}</h2><p class="text-secondary mb-4">{{ $description }}</p><span class="btn tool-open w-100 mt-auto">{{ $label }}を開く →</span></div></a></div>
@endforeach
</div></main>@include('_copyright')</body></html>
