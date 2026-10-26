<header class="navbar"><div class="container-xl gap-3 flex-wrap"><a class="navbar-brand m-0" href="{{ route('home') }}">FF14 関連 <span class="text-secondary fw-normal">/ FFLogs ツール</span></a><a class="btn ms-auto" href="{{ route('api_key.edit') }}">APIキーを設定する</a></div></header>
<nav class="navbar navbar-expand" aria-label="ツール"><div class="container-xl"><div class="navbar-nav flex-row flex-wrap gap-2">
@foreach ([['home','目次'],['mitigation.form','🛡 軽減'],['compare.form','⚔ DPS比較'],['party_search.form','🔍 PT検索']] as [$name,$label])<a class="nav-link {{ request()->routeIs($name) ? 'active' : '' }}" href="{{ route($name) }}">{{ $label }}</a>@endforeach
</div></div></nav>
