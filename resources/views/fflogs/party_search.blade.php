<!DOCTYPE html>
<html lang="ja" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PT構成検索 - FFLogs</title>
    @include('_ui_head')
</head>

<body class="logs-app">
    @include('_tool_nav')
    <div class="container-xl py-4">
        <div class="legacy-flex legacy-items-center legacy-justify-between legacy-mb-4">
            <h1 class="fs-1 fw-bold text-green">🔍 PT構成検索</h1>
            <a href="{{ url('/') }}" class="text-secondary  text-decoration-underline" style="font-size:1rem;">← 目次へ</a>
        </div>

        <p class="text-secondary legacy-mb-6" style="font-size:1rem;">
            ジョブ構成を指定して、一致する討伐ログを探します。
            対象は<strong class="text-body">ランキングに載った討伐ログ</strong>です（絶妖星乱舞で7,000件強）。
            ランキングに出ないクリアは拾えないため、実在するクリアの4割程度が上限になります。
            タンク3などの<strong class="text-body">非標準構成はランキング自体が空</strong>なので見つかりません。
        </p>

        @if ($error)
            <div class="bg-red text-white legacy-p-3 rounded legacy-mb-5" style="font-size:1rem;">{{ $error }}</div>
        @endif

        @if ($warming)
            {{-- このボスのプールはまだ無い。取得は応答後に走っているので、整うまで待って自動で検索し直す。 --}}
            <div id="warmingPanel"
                class="bg-yellow-lt bg-secondary border border-yellow text-yellow legacy-p-4 rounded legacy-mb-5"
                style="font-size:1rem;">
                <div class="legacy-flex legacy-items-center">
                    <svg class="animate-spin legacy-h-5 legacy-w-5 legacy-mr-3 text-yellow" viewBox="0 0 24 24" fill="none">
                        <circle class="legacy-opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="legacy-opacity-75" fill="currentColor"
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
            class="card card-body app-form mb-3">
            @csrf

            {{-- ボス・ランキング種別 --}}
            <div class="legacy-grid legacy-grid-cols-1 legacy-md:grid-cols-2 legacy-gap-4">
                <div>
                    <label class="legacy-block fw-medium text-body legacy-mb-1" style="font-size:1rem;">コンテンツ</label>
                    <select id="zoneSelect" name="zone_id"
                        class="form-select legacy-block legacy-w-full legacy-px-3 legacy-py-2 bg-body-tertiary border border-secondary rounded text-white" style="font-size:1rem;">
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
                    <label class="legacy-block fw-medium text-body legacy-mb-1" style="font-size:1rem;">ボス</label>
                    <select id="encounterSelect" name="encounter_id"
                        class="form-select legacy-block legacy-w-full legacy-px-3 legacy-py-2 bg-body-tertiary border border-secondary rounded text-white" style="font-size:1rem;"></select>
                </div>
            </div>

            {{-- 構成スロット --}}
            <div>
                <div class="legacy-flex legacy-items-center legacy-justify-between legacy-mb-2">
                    <label class="legacy-block fw-medium text-body" style="font-size:1rem;">
                        探すPT構成 <span class="text-secondary">（下のジョブをクリックで追加 / スロットをクリックで削除）</span>
                    </label>
                    <button type="button" id="clearBtn" class="btn text-secondary  text-decoration-underline" style="font-size:1rem;">すべて外す</button>
                </div>
                <div id="slots" class="legacy-flex legacy-flex-wrap legacy-gap-2"></div>
                <div id="roleSummary" class="legacy-mt-2 text-secondary" style="font-size:1rem;"></div>
                @for ($i = 0; $i < 8; $i++)
                    <input type="hidden" name="jobs[]" class="job-input" value="{{ $form['jobs'][$i] ?? '' }}">
                @endfor
            </div>

            {{-- ジョブ選択 --}}
            <div class="legacy-space-y-2">
                @foreach ($jobGroups as $role => $group)
                    <div class="legacy-flex legacy-items-center legacy-flex-wrap legacy-gap-2">
                        <span class="legacy-w-24 legacy-flex-shrink-0 fw-medium" style="font-size:1rem; color: {{ $group['color'] }};">{{ $group['label'] }}</span>
                        @foreach ($group['jobs'] as $type => $job)
                            <button type="button" class="btn job-btn legacy-flex legacy-items-center legacy-px-2 legacy-py-1 bg-body-tertiary  border border-secondary rounded"
                                data-job="{{ $type }}" title="{{ $job['ja'] }}">
                                <img src="{{ asset($job['icon']) }}" alt="{{ $job['ja'] }}">
                                <span class="legacy-ml-1 text-body" style="font-size:1rem;">{{ $job['ja'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endforeach
            </div>

            {{-- 一致条件 --}}
            <div class="legacy-flex legacy-flex-wrap legacy-items-center legacy-gap-6 legacy-pt-1">
                <label class="legacy-flex legacy-items-center legacy-cursor-pointer" style="font-size:1rem;">
                    <input type="radio" name="mode" value="exact" class="form-check-input legacy-mr-2" @checked($form['mode'] === 'exact')>
                    <span>完全一致<span class="text-secondary">（8ジョブすべて指定）</span></span>
                </label>
                <label class="legacy-flex legacy-items-center legacy-cursor-pointer" style="font-size:1rem;">
                    <input type="radio" name="mode" value="include" class="form-check-input legacy-mr-2" @checked($form['mode'] === 'include')>
                    <span>部分一致<span class="text-secondary">（指定したジョブを含む構成）</span></span>
                </label>
            </div>

            <button type="submit"
                class="btn legacy-w-full legacy-py-2 legacy-px-4 bg-green  rounded fw-semibold text-white  "
                style="font-size:1.05rem;">
                この構成のログを探す
            </button>
            <p class="text-secondary" style="font-size:1rem;">
                ※ 初めて検索するボスはランキングを数百ページ分取得するため1〜3分かかります。その間は「準備中」の表示になり、整いしだい自動で検索します（6時間キャッシュされ、2回目以降は即座に返ります）。
            </p>
        </form>

        {{-- 結果 --}}
        @if ($stats)
            <div class="legacy-mt-8">
                <div class="legacy-flex legacy-flex-wrap legacy-items-baseline legacy-gap-x-4 legacy-gap-y-1 legacy-mb-3">
                    <h2 class="fs-2 fw-bold text-green">{{ $stats['encounterName'] ?? 'ボス' }} — {{ $stats['matches'] }} 件ヒット</h2>
                    <span class="text-secondary" style="font-size:1rem;">
                        討伐ログ {{ number_format($stats['pool']) }} 件を照合（うち速度ランキング入り {{ number_format($stats['ranked']) }} 件）
                    </span>
                </div>
                @if (empty($results))
                    <div class="bg-surface border border-secondary rounded legacy-p-6 text-center text-secondary" style="font-size:1rem;">
                        一致するログはランキング上位に見つかりませんでした。<br>
                        部分一致に切り替えるか、ジョブを減らして試してみてください。
                    </div>
                @else
                    <div class="overflow-x-auto bg-surface border border-secondary rounded">
                        <table class="legacy-w-full" style="font-size:1rem;">
                            <thead class="bg-surface text-secondary legacy-border-b border-secondary" style="background-color:#2c3444;">
                                <tr>
                                    <th class="legacy-px-3 legacy-py-2 text-start" title="速度ランキングの順位。圏外は —">順位</th>
                                    <th class="legacy-px-3 legacy-py-2 text-start">構成</th>
                                    <th class="legacy-px-3 legacy-py-2 text-end">タイム</th>
                                    <th class="legacy-px-3 legacy-py-2 text-end">死亡</th>
                                    <th class="legacy-px-3 legacy-py-2 text-start">サーバー / FC</th>
                                    <th class="legacy-px-3 legacy-py-2 text-start">日付</th>
                                    <th class="legacy-px-3 legacy-py-2 text-start">ログ</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($results as $row)
                                    <tr class="legacy-border-b border-secondary " style="border-color:#374151;">
                                        <td class="legacy-px-3 legacy-py-2 text-secondary">{{ $row['rank'] ? '#' . $row['rank'] : '—' }}</td>
                                        <td class="legacy-px-3 legacy-py-2">
                                            <div class="legacy-flex legacy-items-center legacy-gap-1">
                                                @foreach ($row['jobs'] as $job)
                                                    <img src="{{ asset($job['icon']) }}" alt="{{ $job['ja'] }}" title="{{ $job['ja'] }}"
                                                        style="width:26px;height:26px;">
                                                @endforeach
                                            </div>
                                        </td>
                                        <td class="legacy-px-3 legacy-py-2 text-end font-monospace text-green">{{ $row['duration'] }}</td>
                                        <td class="legacy-px-3 legacy-py-2 text-end {{ $row['deaths'] ? 'text-red' : 'text-secondary' }}">{{ $row['deaths'] ?? '—' }}</td>
                                        <td class="legacy-px-3 legacy-py-2 text-body">
                                            {{ $row['server'] }}
                                            @if ($row['guild'])
                                                <span class="text-secondary legacy-block" style="font-size:1rem;">{{ $row['guild'] }}</span>
                                            @endif
                                        </td>
                                        <td class="legacy-px-3 legacy-py-2 text-secondary">{{ $row['date'] }}</td>
                                        <td class="legacy-px-3 legacy-py-2 text-nowrap">
                                            <a href="{{ $row['url'] }}" target="_blank" rel="noopener"
                                                class="text-primary  text-decoration-underline">FFLogs</a>
                                            <form action="{{ route('analyze') }}" method="POST" target="_blank" class="card card-body app-form mb-3">
                                                @csrf
                                                <input type="hidden" name="url" value="{{ $row['url'] }}">
                                                <button type="submit" class="btn legacy-ml-2 text-green  text-decoration-underline">軽減</button>
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
        @php $clientJobs = collect($jobGroups)->flatMap(fn($g) => $g['jobs'])->map(fn($job) => array_replace($job, ['icon' => asset(ltrim($job['icon'], '/'))])); @endphp
        const JOB_META = @json($clientJobs);
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
                const cell = document.createElement('button');
                cell.type = 'button';
                cell.className = 'slot legacy-flex legacy-items-center legacy-justify-center rounded border ' +
                    (type ? 'bg-body-tertiary border-secondary legacy-cursor-pointer' : 'bg-body border-secondary legacy-border-dashed');
                if (type) {
                    const meta = JOB_META[type];
                    cell.title = meta.ja + '（クリックで外す）';
                    cell.setAttribute('aria-label', meta.ja + 'を構成から外す');
                    cell.innerHTML = '<img src="' + meta.icon + '" alt="' + meta.ja + '">';
                    cell.addEventListener('click', () => { picked.splice(i, 1); render(); });
                } else {
                    cell.innerHTML = '<span class="text-secondary" style="font-size:1.4rem;">+</span>';
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
    <div class="legacy-flex legacy-justify-center legacy-pb-8">@include('_copyright')</div>
</body>

</html>
