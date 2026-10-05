<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PT構成検索 - FFLogs</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        .slot { width: 56px; height: 56px; }
        .job-btn img, .slot img { width: 36px; height: 36px; }
        .job-btn { transition: background-color .12s, border-color .12s; }
    </style>
</head>

<body class="bg-gray-900 text-gray-100 min-h-screen py-8">
    <div class="w-full max-w-6xl mx-auto px-4">
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-2xl font-bold text-emerald-300">🔍 PT構成検索</h1>
            <a href="{{ url('/') }}" class="text-gray-400 hover:text-indigo-400 underline" style="font-size:0.95rem;">← 目次へ</a>
        </div>

        <p class="text-gray-400 mb-6" style="font-size:0.95rem;">
            ジョブ構成を指定して、一致する討伐ログを探します。
            対象は<strong class="text-gray-300">ランキングに載った討伐ログ</strong>です（絶妖星乱舞で7,000件強）。
            ランキングに出ないクリアは拾えないため、実在するクリアの4割程度が上限になります。
            タンク3などの<strong class="text-gray-300">非標準構成はランキング自体が空</strong>なので見つかりません。
        </p>

        @if ($error)
            <div class="bg-red-600 text-white p-3 rounded mb-5" style="font-size:0.95rem;">{{ $error }}</div>
        @endif

        @if ($warming)
            {{-- このボスのプールはまだ無い。取得は応答後に走っているので、整うまで待って自動で検索し直す。 --}}
            <div id="warmingPanel"
                class="bg-amber-900 bg-opacity-40 border border-amber-600 text-amber-100 p-4 rounded mb-5"
                style="font-size:0.95rem;">
                <div class="flex items-center">
                    <svg class="animate-spin h-5 w-5 mr-3 text-amber-300" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor"
                            d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    <span>
                        このボスの討伐ログを初めて取得しています（<span id="warmingElapsed">0</span> 秒経過）。<br>
                        ランキングを数百ページ分まとめて読むため <strong>1〜3分</strong> かかります。
                        取得できしだい自動で検索を実行します。このページを開いたままにしてください。
                    </span>
                </div>
            </div>

            <script>
                (function () {
                    var encounterId = @json($form['encounter_id']);
                    var statusUrl = @json(route('party_search.status'));
                    var started = Date.now();
                    var elapsed = document.getElementById('warmingElapsed');

                    setInterval(function () {
                        elapsed.textContent = Math.round((Date.now() - started) / 1000);
                    }, 1000);

                    // 取得が終わったら、入力値を保ったままフォームを送り直す
                    (function poll() {
                        fetch(statusUrl + '?encounter_id=' + encodeURIComponent(encounterId), {
                            headers: { 'Accept': 'application/json' }
                        })
                            .then(function (r) { return r.json(); })
                            .then(function (d) {
                                if (d.ready) {
                                    document.getElementById('searchForm').submit();
                                } else {
                                    setTimeout(poll, 4000);
                                }
                            })
                            .catch(function () { setTimeout(poll, 8000); });
                    })();
                })();
            </script>
        @endif

        @include('fflogs._api_key_status')

        <form action="{{ route('party_search') }}" method="POST" id="searchForm"
            class="bg-gray-800 rounded-lg shadow-xl border border-gray-700 p-5 space-y-5">
            @csrf

            {{-- ボス・ランキング種別 --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block font-medium text-gray-300 mb-1" style="font-size:0.95rem;">コンテンツ</label>
                    <select id="zoneSelect" name="zone_id"
                        class="block w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-md text-white" style="font-size:0.95rem;">
                        @foreach ($zoneTree as $exp)
                            <optgroup label="{{ $exp['name'] }}">
                                @foreach ($exp['zones'] as $zone)
                                    <option value="{{ $zone['id'] }}" @selected($form['zone_id'] == $zone['id'])>{{ $zone['name'] }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block font-medium text-gray-300 mb-1" style="font-size:0.95rem;">ボス</label>
                    <select id="encounterSelect" name="encounter_id"
                        class="block w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-md text-white" style="font-size:0.95rem;"></select>
                </div>
            </div>

            {{-- 構成スロット --}}
            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="block font-medium text-gray-300" style="font-size:0.95rem;">
                        探すPT構成 <span class="text-gray-500">（下のジョブをクリックで追加 / スロットをクリックで削除）</span>
                    </label>
                    <button type="button" id="clearBtn" class="text-gray-400 hover:text-rose-300 underline" style="font-size:0.9rem;">すべて外す</button>
                </div>
                <div id="slots" class="flex flex-wrap gap-2"></div>
                <div id="roleSummary" class="mt-2 text-gray-400" style="font-size:0.9rem;"></div>
                @for ($i = 0; $i < 8; $i++)
                    <input type="hidden" name="jobs[]" class="job-input" value="{{ $form['jobs'][$i] ?? '' }}">
                @endfor
            </div>

            {{-- ジョブ選択 --}}
            <div class="space-y-2">
                @foreach ($jobGroups as $role => $group)
                    <div class="flex items-center flex-wrap gap-2">
                        <span class="w-24 flex-shrink-0 font-medium" style="font-size:0.9rem; color: {{ $group['color'] }};">{{ $group['label'] }}</span>
                        @foreach ($group['jobs'] as $type => $job)
                            <button type="button" class="job-btn flex items-center px-2 py-1 bg-gray-700 hover:bg-gray-600 border border-gray-600 rounded"
                                data-job="{{ $type }}" title="{{ $job['ja'] }}">
                                <img src="{{ asset($job['icon']) }}" alt="{{ $job['ja'] }}">
                                <span class="ml-1 text-gray-200" style="font-size:0.85rem;">{{ $job['ja'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endforeach
            </div>

            {{-- 一致条件 --}}
            <div class="flex flex-wrap items-center gap-6 pt-1">
                <label class="flex items-center cursor-pointer" style="font-size:0.95rem;">
                    <input type="radio" name="mode" value="exact" class="mr-2" @checked($form['mode'] === 'exact')>
                    <span>完全一致<span class="text-gray-500">（8ジョブすべて指定）</span></span>
                </label>
                <label class="flex items-center cursor-pointer" style="font-size:0.95rem;">
                    <input type="radio" name="mode" value="include" class="mr-2" @checked($form['mode'] === 'include')>
                    <span>部分一致<span class="text-gray-500">（指定したジョブを含む構成）</span></span>
                </label>
            </div>

            <button type="submit"
                class="w-full py-2 px-4 bg-emerald-600 hover:bg-emerald-700 rounded-md font-semibold text-white transition duration-200"
                style="font-size:1.05rem;">
                この構成のログを探す
            </button>
            <p class="text-gray-500" style="font-size:0.85rem;">
                ※ 初めて検索するボスはランキングを数百ページ分取得するため1〜3分かかります。その間は「準備中」の表示になり、整いしだい自動で検索します（6時間キャッシュされ、2回目以降は即座に返ります）。
            </p>
        </form>

        {{-- 結果 --}}
        @if ($stats)
            <div class="mt-8">
                <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1 mb-3">
                    <h2 class="text-xl font-bold text-emerald-300">{{ $stats['encounterName'] ?? 'ボス' }} — {{ $stats['matches'] }} 件ヒット</h2>
                    <span class="text-gray-500" style="font-size:0.9rem;">
                        討伐ログ {{ number_format($stats['pool']) }} 件を照合（うち速度ランキング入り {{ number_format($stats['ranked']) }} 件）
                    </span>
                </div>
                @if (empty($results))
                    <div class="bg-gray-800 border border-gray-700 rounded-lg p-6 text-center text-gray-400" style="font-size:0.95rem;">
                        一致するログはランキング上位に見つかりませんでした。<br>
                        部分一致に切り替えるか、ジョブを減らして試してみてください。
                    </div>
                @else
                    <div class="overflow-x-auto bg-gray-800 border border-gray-700 rounded-lg">
                        <table class="w-full" style="font-size:0.9rem;">
                            <thead class="bg-gray-750 text-gray-400 border-b border-gray-700" style="background-color:#2c3444;">
                                <tr>
                                    <th class="px-3 py-2 text-left" title="速度ランキングの順位。圏外は —">順位</th>
                                    <th class="px-3 py-2 text-left">構成</th>
                                    <th class="px-3 py-2 text-right">タイム</th>
                                    <th class="px-3 py-2 text-right">死亡</th>
                                    <th class="px-3 py-2 text-left">サーバー / FC</th>
                                    <th class="px-3 py-2 text-left">日付</th>
                                    <th class="px-3 py-2 text-left">ログ</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($results as $row)
                                    <tr class="border-b border-gray-700 hover:bg-gray-750" style="border-color:#374151;">
                                        <td class="px-3 py-2 text-gray-400">{{ $row['rank'] ? '#' . $row['rank'] : '—' }}</td>
                                        <td class="px-3 py-2">
                                            <div class="flex items-center gap-1">
                                                @foreach ($row['jobs'] as $job)
                                                    <img src="{{ asset($job['icon']) }}" alt="{{ $job['ja'] }}" title="{{ $job['ja'] }}"
                                                        style="width:26px;height:26px;">
                                                @endforeach
                                            </div>
                                        </td>
                                        <td class="px-3 py-2 text-right font-mono text-emerald-300">{{ $row['duration'] }}</td>
                                        <td class="px-3 py-2 text-right {{ $row['deaths'] ? 'text-rose-300' : 'text-gray-500' }}">{{ $row['deaths'] ?? '—' }}</td>
                                        <td class="px-3 py-2 text-gray-300">
                                            {{ $row['server'] }}
                                            @if ($row['guild'])
                                                <span class="text-gray-500 block" style="font-size:0.85rem;">{{ $row['guild'] }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-gray-400">{{ $row['date'] }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap">
                                            <a href="{{ $row['url'] }}" target="_blank" rel="noopener"
                                                class="text-indigo-300 hover:text-indigo-200 underline">FFLogs</a>
                                            <form action="{{ route('analyze') }}" method="POST" target="_blank" class="inline">
                                                @csrf
                                                <input type="hidden" name="url" value="{{ $row['url'] }}">
                                                <button type="submit" class="ml-2 text-emerald-300 hover:text-emerald-200 underline">軽減</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif
    </div>

    <script>
        // ゾーン→ボスの対応表。ボスのselectはゾーン選択に合わせてここから組み立てる。
        const ZONE_TREE = @json($zoneTree);
        const JOB_META = @json(collect($jobGroups)->flatMap(fn($g) => $g['jobs']));
        const ROLE_LABELS = @json(collect($jobGroups)->map(fn($g) => $g['label']));
        const INITIAL_ENCOUNTER = @json($form['encounter_id']);

        const zoneSelect = document.getElementById('zoneSelect');
        const encounterSelect = document.getElementById('encounterSelect');
        const slotsEl = document.getElementById('slots');
        const summaryEl = document.getElementById('roleSummary');
        const inputs = Array.from(document.querySelectorAll('.job-input'));

        const encountersByZone = {};
        ZONE_TREE.forEach(exp => exp.zones.forEach(z => { encountersByZone[z.id] = z.encounters; }));

        function renderEncounters(keepId) {
            const list = encountersByZone[zoneSelect.value] || [];
            encounterSelect.innerHTML = '';
            list.forEach(enc => {
                const opt = document.createElement('option');
                opt.value = enc.id;
                opt.textContent = enc.name;
                if (String(enc.id) === String(keepId)) opt.selected = true;
                encounterSelect.appendChild(opt);
            });
        }

        // 選択中のジョブ（最大8）。hidden input と表示スロットの両方をここから作り直す。
        let picked = inputs.map(i => i.value).filter(v => v);

        function render() {
            slotsEl.innerHTML = '';
            for (let i = 0; i < 8; i++) {
                const type = picked[i];
                const cell = document.createElement('div');
                cell.className = 'slot flex items-center justify-center rounded border ' +
                    (type ? 'bg-gray-700 border-gray-500 cursor-pointer' : 'bg-gray-900 border-gray-700 border-dashed');
                if (type) {
                    const meta = JOB_META[type];
                    cell.title = meta.ja + '（クリックで外す）';
                    cell.innerHTML = '<img src="' + meta.icon + '" alt="' + meta.ja + '">';
                    cell.addEventListener('click', () => { picked.splice(i, 1); render(); });
                } else {
                    cell.innerHTML = '<span class="text-gray-600" style="font-size:1.4rem;">+</span>';
                }
                slotsEl.appendChild(cell);
            }

            inputs.forEach((input, i) => { input.value = picked[i] || ''; });

            const counts = {};
            picked.forEach(t => { const r = JOB_META[t].role; counts[r] = (counts[r] || 0) + 1; });
            const parts = Object.keys(ROLE_LABELS).filter(r => counts[r]).map(r => ROLE_LABELS[r] + ' ' + counts[r]);
            summaryEl.textContent = picked.length
                ? picked.length + '/8 選択中 … ' + parts.join(' / ')
                : 'まだ何も選ばれていません。';
        }

        document.querySelectorAll('.job-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (picked.length >= 8) return;
                picked.push(btn.dataset.job);
                render();
            });
        });
        document.getElementById('clearBtn').addEventListener('click', () => { picked = []; render(); });
        zoneSelect.addEventListener('change', () => renderEncounters(null));

        renderEncounters(INITIAL_ENCOUNTER);
        render();
    </script>
</body>

</html>
