function togglePlayers() {
    document.body.classList.toggle("hide-players");
}

// ビュー切り替え（軽減・バリア ↔ シナジー・バフ ↔ デスチェッカー）
function switchView(name) {
    const views = { mitigation: 'view-mitigation', synergy: 'view-synergy', death: 'view-death' };
    for (const [key, id] of Object.entries(views)) {
        const view = document.getElementById(id);
        const btn = document.getElementById('tab-btn-' + key);
        const active = (key === name);
        if (view) view.classList.toggle('hidden', !active);
        if (btn) {
            btn.classList.toggle('bg-gray-700', active);
            btn.classList.toggle('text-white', active);
            btn.classList.toggle('border-gray-600', active);
            btn.classList.toggle('bg-gray-900', !active);
            btn.classList.toggle('text-gray-400', !active);
            btn.classList.toggle('border-gray-700', !active);
        }
    }
}

// Global state
let hiddenActions = new Set(); // Specific actions hidden by user

// Special Category Flags
let hideAA = false; // Default: Visible
let hideDoTs = true; // Default: Hidden

function getUniqueActions() {
    const actionSet = new Set();
    document.querySelectorAll('tbody tr .ability-name').forEach(el => {
        actionSet.add(el.innerText.trim());
    });
    return Array.from(actionSet).sort();
}

function openActionFilterModal() {
    const modal = document.getElementById('actionFilterModal');
    const list = document.getElementById('actionList');
    const actions = getUniqueActions();

    list.innerHTML = '';

    // 1. Special Categories
    const specialCats = [{
        id: 'special_aa',
        label: 'Auto Attacks',
        checked: !hideAA
    },
    {
        id: 'special_dot',
        label: 'DoTs (Pain/Bleed/etc)',
        checked: !hideDoTs
    }
    ];

    specialCats.forEach(cat => {
        const div = document.createElement('div');
        div.className = 'flex items-center space-x-2 col-span-full bg-gray-700 p-2 rounded mb-1';
        div.innerHTML = `
            <input type="checkbox" id="${cat.id}" 
                class="form-checkbox bg-gray-600 border-gray-500 text-green-500 rounded h-4 w-4"
                ${cat.checked ? 'checked' : ''}>
            <label for="${cat.id}" class="text-sm text-white font-bold cursor-pointer select-none">
                ${cat.label}
            </label>
        `;
        list.appendChild(div);
    });

    // Separator
    const sep = document.createElement('div');
    sep.className = 'col-span-full h-px bg-gray-600 my-2';
    list.appendChild(sep);

    // 2. Individual Actions
    actions.forEach(action => {
        const isHidden = hiddenActions.has(action);
        const div = document.createElement('div');
        div.className = 'flex items-center space-x-2';
        div.innerHTML = `
            <input type="checkbox" id="act_${action}" value="${action}" 
                class="action-checkbox form-checkbox bg-gray-700 border-gray-500 text-blue-500 rounded h-4 w-4"
                ${!isHidden ? 'checked' : ''}>
            <label for="act_${action}" class="text-sm text-gray-300 truncate cursor-pointer select-none" title="${action}">
                ${action}
            </label>
        `;
        list.appendChild(div);
    });

    modal.classList.remove('hidden');
}

function closeActionFilterModal() {
    document.getElementById('actionFilterModal').classList.add('hidden');
}

async function saveFullTimeline() {
    const table = document.querySelector('table'); // The main timeline table
    if (!table) return;

    // Use a proxy to avoid CORS issues with rpglogs icons
    const proxyBase = '/image-proxy?url=';

    try {
        const canvas = await html2canvas(table, {
            useCORS: true,
            allowTaint: true,
            backgroundColor: '#111827', // gray-900 matches background
            onclone: (clonedDoc) => {
                const clonedTable = clonedDoc.querySelector('table');

                // 1. Handle Proxy for Images
                const images = clonedTable.querySelectorAll('img');
                const timestamp = Date.now();
                images.forEach(img => {
                    const src = img.src;
                    // Only proxy if HTTP(S) and not already proxied
                    if (src && src.startsWith('http') && !src.includes('image-proxy')) {
                        // Check for base64 or data uri? No startswith http handles that.
                        // Add timestamp to bust cache
                        img.src = proxyBase + encodeURIComponent(src) + '&t=' + timestamp;
                        // Force crossOrigin to anonymous just in case (though we use Proxy)
                        img.crossOrigin = 'anonymous';
                    }
                });

                // 2. Disable Sticky Headers (force them to stay in place at top)
                const stickies = clonedTable.querySelectorAll('[style*="sticky"]');
                stickies.forEach(el => {
                    el.style.position = 'static';
                });
            }
        });

        // Trigger Download
        const link = document.createElement('a');
        link.download = `timeline_full_${Date.now()}.png`;
        link.href = canvas.toDataURL('image/png');
        link.click();
    } catch (err) {
        console.error('Screenshot failed:', err);
        alert('Screenshot failed. Check console.');
    }
}

function toggleAllActions(check) {
    document.querySelectorAll('#actionList .action-checkbox').forEach(box => {
        box.checked = check;
    });
}

function applyActionFilter() {
    // Update Special Flags
    const aaBox = document.getElementById('special_aa');
    const dotBox = document.getElementById('special_dot');

    if (aaBox) hideAA = !aaBox.checked;
    if (dotBox) hideDoTs = !dotBox.checked;

    // Update Individual Actions
    hiddenActions.clear();
    document.querySelectorAll('#actionList .action-checkbox').forEach(box => {
        if (!box.checked) {
            hiddenActions.add(box.value);
        }
    });

    closeActionFilterModal();
    applyFilters();
}

function applyFilters() {
    const rows = document.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const abilityCell = row.querySelector('.ability-name');
        if (!abilityCell) return;

        const abilityName = abilityCell.innerText.trim();
        const isDot = row.classList.contains('row-dot');
        let hide = false;

        // 1. Global Categories
        // Auto Attacks
        if (hideAA && (abilityName === 'Attack' || abilityName === '攻撃')) hide = true;

        // DoTs
        if (hideDoTs) {
            if (isDot || abilityName.includes('Combined DoTs') || abilityName.includes('DoT') ||
                abilityName.includes('Pain') || abilityName.includes('ペイン') ||
                abilityName.includes('Bleed') || abilityName.includes('出血') ||
                abilityName.includes('裂傷') || abilityName.includes('Burn') ||
                abilityName.includes('火傷') || abilityName.includes('Dropsy') ||
                abilityName.includes('水毒') || abilityName.includes('Electrocution') ||
                abilityName.includes('感電') || abilityName.includes('Poison') ||
                abilityName.includes('毒') || abilityName.includes('Continuous Damage') ||
                abilityName.includes('継続ダメージ')) {
                hide = true;
            }
        }

        // 2. Custom Action Filter
        if (hiddenActions.has(abilityName)) {
            hide = true;
        }

        if (hide) {
            row.classList.add('hidden');
        } else {
            row.classList.remove('hidden');
        }
    });
}
// Apply filters on load
document.addEventListener('DOMContentLoaded', applyFilters);

// --- Player Modal Logic ---

function formatTime(seconds) {
    const min = Math.floor(seconds / 60);
    const sec = Math.floor(seconds % 60);
    return `${min}:${sec.toString().padStart(2, '0')}`;
}

function openPlayerTimeline(playerName) {
    const modal = document.getElementById('playerTimelineModal');
    const title = document.getElementById('playerModalTitle');
    const content = document.getElementById('playerTimelineContent');

    // Access global variables passed from Blade
    const playerDetails = window.playerDetails || {};
    const playerTimelines = window.playerTimelines || {};

    const pDetail = playerDetails[playerName];
    const timeline = playerTimelines[playerName] || [];

    // Set Title with Icon if available
    let iconHtml = '';
    if (pDetail && pDetail.icon) {
        iconHtml = `<img src="${pDetail.icon}" class="w-6 h-6 mr-2 inline-block rounded-sm">`;
    }
    title.innerHTML =
        `${playerName} <span class="text-sm text-gray-400 font-normal">(${pDetail.job || 'Unknown'})</span>`;

    // Build Content
    if (pDetail && pDetail.icon) {
        iconHtml = `<img src="${pDetail.icon}" class="w-6 h-6 mr-2 inline-block rounded-sm">`;
    }
    title.innerHTML =
        `${pDetail.name} <span class="text-sm text-gray-400 font-normal">&nbsp;[${pDetail.job}]</span>`;

    // Clear Body
    content.innerHTML = '';

    // Add Disclaimer
    content.innerHTML += '<div class="text-xs text-gray-500 mb-2 pl-2 border-l-2 border-gray-600">※ 戦闘開始前（0:00以前）に使用されたスキルは表示されません。</div>';

    if (timeline.length === 0) {
        content.innerHTML += '<div class="text-gray-400 text-center py-4">No major cooldowns used.</div>';
    } else {
        let html = `
            <table class="w-full text-left border-collapse text-sm text-gray-300">
                <thead>
                    <tr class="border-b border-gray-700 text-gray-400 font-mono text-xs uppercase tracking-wider">
                        <th class="p-2 w-20 text-right">Time</th>
                        <th class="p-2 text-left">Action</th>
                        <th class="p-2 pl-4">Skill</th>
                        <th class="p-2 pl-4">対象</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-700 bg-gray-800">
        `;

        timeline.forEach(event => {
            const timeStr = formatTime(event.rel_time);
            const iconUrl = event.icon ?
                `/icons/abilities/${event.icon}` :
                null;

            const iconHtml = iconUrl ?
                `<img src="${iconUrl}" class="w-6 h-6 object-contain rounded-sm border ${event.broken ? "border-red-500 border-2" : "border-gray-600"}" onerror="this.style.display='none'">` :
                '<div class="w-6 h-6"></div>';

            // 対象（ターゲット）表示。自分自身への使用は「自分」、他者対象は名前を強調
            const targetHtml = event.target ?
                (event.self_target ?
                    `<span class="text-gray-500 text-xs">自分</span>` :
                    `<span class="text-cyan-300 font-medium">${event.target}</span>`) :
                `<span class="text-gray-600">-</span>`;

            html += `
                <tr class="hover:bg-gray-700 transition-colors">
                    <td class="p-2 text-right font-mono text-gray-400">${timeStr}</td>
                    <td class="p-2 text-left text-gray-300 text-xs align-middle">
                        ${event.enemy_action || "-"}
                    </td>
                    <td class="p-2 pl-4 align-middle flex items-center gap-2">
                        ${iconHtml}
                        <span class="text-gray-200 font-medium">${event.name}${event.broken ? " <span title='Barrier Broken' class='text-red-400 text-xs'>💔</span>" : ""}</span>
                    </td>
                    <td class="p-2 pl-4 align-middle">${targetHtml}</td>
                </tr>
            `;
        });
        html += '</tbody></table>';
        content.innerHTML += html;
    }

    document.getElementById('playerTimelineModal').classList.remove('hidden');
}

function closePlayerTimelineModal() {
    document.getElementById('playerTimelineModal').classList.add('hidden');
}

function openJobTimeline(jobName) {
    // Access global variables
    const playerDetails = window.playerDetails || {};

    // Find all players with this job
    const targets = [];
    for (const [name, detail] of Object.entries(playerDetails)) {
        if (detail.job === jobName) {
            targets.push(name);
        }
    }

    if (targets.length === 0) {
        console.warn(`No player found for job: ${jobName}`);
        return;
    }

    openPlayerTimeline(targets[0]);
}

function togglePlayerColumn(playerName) {
    // Toggle Header and Data Cells
    const cells = document.querySelectorAll(`[data-player="${playerName.replace(/"/g, '\\"')}"]`);
    cells.forEach(c => {
        if (c.style.display === 'none' || c.classList.contains('hidden')) {
            c.style.display = '';
            c.classList.remove('hidden');
        } else {
            c.style.display = 'none';
            c.classList.add('hidden');
        }
    });
}

function openActionDetail(actionName, actionTime) {
    const modal = document.getElementById('actionDetailModal');
    const title = document.getElementById('actionDetailTitle');
    const content = document.getElementById('actionDetailContent');

    title.innerText = `この被弾に効いていた軽減・支援: ${actionName}`;
    content.innerHTML = '';

    const playerDetails = window.playerDetails || {};
    const playerTimelines = window.playerTimelines || {};
    const events = window.enemyEvents || [];
    const mitCols = window.mitCols || {}; // 列キー -> {icon, name, group}
    const shortName = (n) => (n ? String(n).split(' ')[0] : n); // フルネーム -> ファーストネーム表示

    // マトリクスと同じ cols（buffsベースの実効軽減）を正データにする。
    // 詠唱ログ(playerTimelines)依存だと、詠唱イベントを出さない/フィルタ外の軽減
    // （トルバドゥール・野戦治療の陣・鼓舞・サンサイン等）が軒並み漏れるため。
    let ev = events.find(e => e.ability === actionName && e.timestamp === actionTime);
    if (!ev) {
        let bestDiff = Infinity;
        for (const e of events) {
            if (e.ability !== actionName) continue;
            const d = Math.abs((e.timestamp || 0) - actionTime);
            if (d < bestDiff) { bestDiff = d; ev = e; }
        }
    }

    // 補助: playerTimelines から (使用者, スキル名) の残り秒を引く（取れれば併記）
    const remainingFor = (playerName, skillName) => {
        const tl = playerTimelines[playerName];
        if (!tl) return null;
        let best = null;
        for (const e of tl) {
            if (e.name !== skillName) continue;
            const pStart = e.timestamp;
            const pEnd = e.expires || (pStart + 15000);
            if (pStart <= actionTime && pEnd >= actionTime) {
                const rem = (pEnd - actionTime) / 1000;
                if (best === null || rem < best) best = rem;
            }
        }
        return best;
    };

    let html = `
        <table class="w-full text-left border-collapse text-sm text-gray-300">
            <thead>
                <tr class="border-b border-gray-700 text-gray-400 text-xs uppercase tracking-wider">
                    <th class="p-2">軽減スキル</th>
                    <th class="p-2">使用者</th>
                    <th class="p-2">対象（誰に入っていたか）</th>
                    <th class="p-2 text-right">残り時間</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-700 bg-gray-800">
    `;

    let foundAny = false;

    if (ev && ev.cols) {
        for (const [colKey, col] of Object.entries(ev.cols)) {
            if (!col || !col.active) continue;
            foundAny = true;

            const meta = mitCols[colKey] || {};
            const displayName = meta.name || colKey;
            const skillIcon = meta.icon
                ? `<img src="/icons/abilities/${meta.icon}" class="w-6 h-6 object-contain rounded-sm border border-gray-600 inline-block mr-2 align-middle">`
                : '';
            const brokenTag = col.is_broken ? " <span title='バリア消滅' class='text-red-400 text-xs'>💔</span>" : "";

            const sources = (col.sources && col.sources.length) ? col.sources : null;

            // 残り時間：サーバ算出(col.remaining=実際に剥がれるまで)を優先、無ければ詠唱ログから補完
            let rem = (typeof col.remaining === 'number') ? col.remaining : null;
            if (rem === null && sources) {
                for (const s of sources) {
                    const r = remainingFor(s, displayName);
                    if (r !== null && (rem === null || r < rem)) rem = r;
                }
            }
            let remStr = '<span class="text-gray-600">-</span>';
            if (rem !== null) {
                const c = rem < 2 ? 'text-yellow-400' : 'text-green-400';
                remStr = `<span class="font-mono ${c}">${rem.toFixed(1)}s</span>`;
            }

            let srcHtml;
            if (sources) {
                srcHtml = sources.map(s => {
                    const pd = playerDetails[s] || {};
                    const icon = pd.icon ? `<img src="${pd.icon}" class="w-5 h-5 inline-block rounded-sm mr-1 align-middle">` : '';
                    const job = pd.job ? `<span class="text-gray-500 text-xs ml-1">${pd.job}</span>` : '';
                    return `${icon}<span class="text-white font-medium">${shortName(s)}</span>${job}`;
                }).join('<span class="text-gray-600 mx-1">/</span>');
            } else {
                srcHtml = `<span class="text-gray-500 text-xs">（効果のみ検出）</span>`;
            }

            // 対象（誰に入っていたか）：各被弾者のbuffsに、この軽減のステータスIDが乗っているか判定。
            // デバフ系（リプライザル等＝ボスに付与）はプレイヤーに乗らないので「-」になる。
            const colIds = (meta.ids || []).map(Number);
            const partySize = Object.keys(playerDetails).length; // フルパーティ人数（被弾グループ人数ではない）
            let targets = [];
            if (colIds.length && ev.players) {
                for (const [pname, pdata] of Object.entries(ev.players)) {
                    const pbuffs = (pdata.buffs || []).map(Number);
                    if (colIds.some(id => pbuffs.includes(id))) targets.push(pname);
                }
            }
            let tgtHtml;
            if (targets.length === 0) {
                tgtHtml = `<span class="text-gray-600">-</span>`;
            } else if (partySize > 0 && targets.length >= partySize) {
                tgtHtml = `<span class="text-cyan-300 font-medium">全員</span>`;
            } else {
                // 対象を実名で列挙（バリア等が誰に入っていたか分かるように）
                tgtHtml = targets.map(t => {
                    const pd = playerDetails[t] || {};
                    const icon = pd.icon ? `<img src="${pd.icon}" class="w-5 h-5 inline-block rounded-sm mr-1 align-middle">` : '';
                    return `${icon}<span class="text-cyan-300">${shortName(t)}</span>`;
                }).join('<span class="text-gray-600 mx-1">/</span>');
            }

            html += `
                <tr class="hover:bg-gray-700">
                    <td class="p-2 flex items-center">${skillIcon}<span class="text-gray-200">${displayName}${brokenTag}</span></td>
                    <td class="p-2">${srcHtml}</td>
                    <td class="p-2">${tgtHtml}</td>
                    <td class="p-2 text-right">${remStr}</td>
                </tr>
            `;
        }
    }

    if (!foundAny) {
        html += `<tr><td colspan="4" class="p-4 text-center text-gray-500">この被弾に効いていた軽減はありません</td></tr>`;
    }

    html += '</tbody></table>';
    content.innerHTML = html;
    modal.classList.remove('hidden');
}

function closeActionDetailModal() {
    document.getElementById('actionDetailModal').classList.add('hidden');
}

function downloadHojoringXML() {
    const events = window.enemyEvents || [];
    const fight = window.fightMetadata || {};

    if (events.length === 0) {
        alert("No timeline events to export.");
        return;
    }

    const now = new Date();
    const dateStr = now.toISOString().slice(0, 10).replace(/-/g, "");

    const zoneName = fight.gameZone ? fight.gameZone.name : (fight.zoneName || "Unknown Zone");
    const fightName = fight.name || "Unknown Boss";

    let xml = '<?xml version="1.0" encoding="utf-8"?>\n';
    xml += '<timeline>\n';
    xml += `  <name>${zoneName}</name>\n`;
    xml += '  <rev>Rev.1</rev>\n';
    xml += '  <description>\n';
    xml += `        ${dateStr}:Rev1 Generated from FFLogs Analysis\n`;
    xml += '    </description>\n';
    xml += '  <author>FFLogsToTimeline</author>\n';
    xml += '  <license>CC BY-SA</license>\n';
    xml += `  <zone>${zoneName}</zone>\n`;
    xml += '  <locale>JA</locale>\n';
    xml += '  <start>0039::戦闘開始！</start>\n';
    xml += '  <default target-element="Activity" target-attr="notice-o" value="-4" />\n';
    xml += '  <default target-element="Activity" target-attr="sync-s" value="-3" />\n';
    xml += '  <default target-element="Activity" target-attr="sync-e" value="3" />\n\n';
    xml += '  <!-- Events -->\n';

    events.forEach(event => {
        const ability = event.ability || "";

        // 1. Check Filters
        let hide = false;
        if (hideAA && (ability === "Attack" || ability === "攻撃")) hide = true;
        if (hideDoTs) {
            if (event.is_dot ||
                ability.includes("Combined DoTs") || ability.includes("DoT") ||
                ability.includes("Pain") || ability.includes("ペイン") ||
                ability.includes("Bleed") || ability.includes("出血") ||
                ability.includes("裂傷") || ability.includes("Burn") ||
                ability.includes("火傷") || ability.includes("Dropsy") ||
                ability.includes("水毒") || ability.includes("Electrocution") ||
                ability.includes("感電") || ability.includes("Poison") ||
                ability.includes("毒") || ability.includes("Continuous Damage") ||
                ability.includes("継続ダメージ")) {
                hide = true;
            }
        }
        if (hiddenActions.has(ability)) hide = true;

        if (hide) return;

        const time = Math.round(event.rel_time);
        const escapedAbility = ability.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");

        const syncText = `${fightName}の「${escapedAbility}」`;

        xml += `<a time="${time}" text="${escapedAbility}" sync="${syncText}" />\n`;
    });

    xml += "</timeline>";

    const blob = new Blob([xml], { type: "application/xml" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `${fightName.replace(/\s+/g, "_")}.xml`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// Save Modal as Image (Full Scroll Capture)
function saveModalAsImage(elementId, filename) {
    const element = document.getElementById(elementId);
    if (!element) {
        alert('Element not found');
        return;
    }

    // 1. Create a deep clone to manipulate styling without affecting UI
    const clone = element.cloneNode(true);

    // 2. Style the clone to show full content
    clone.style.position = 'absolute';
    clone.style.left = '-9999px';
    clone.style.top = '0';
    clone.style.width = element.clientWidth + 'px'; // Maintain width
    clone.style.height = 'auto'; // Expand height
    clone.style.overflow = 'visible'; // Show all content
    clone.style.maxHeight = 'none';

    // Ensure text colors are visible (if relying on parent bg)
    clone.style.backgroundColor = '#1f2937'; // bg-gray-800 equiv
    clone.style.color = 'white';

    document.body.appendChild(clone);

    // 3. Capture with html2canvas
    html2canvas(clone, {
        backgroundColor: '#1f2937',
        scale: 2, // High resolution
        logging: false,
        useCORS: true
    }).then(canvas => {
        // 4. Download
        const link = document.createElement('a');
        link.download = filename;
        link.href = canvas.toDataURL('image/png');
        link.click();

        // 5. Cleanup
        document.body.removeChild(clone);
    }).catch(err => {
        console.error('Image capture failed:', err);
        alert('Failed to save image.');
        document.body.removeChild(clone);
    });
}

/* ============================================================
 * 被弾内訳モーダル（Hit Dmg セルのクリックで開く）
 * 「どの軽減スキルが何%効いて、いくら減らしたか」「バリアがいくら吸ったか」
 * 「HPに実際に向かった量（実被弾）」を1画面で見せる。
 * ==========================================================*/

// XSS/レイアウト崩れ防止（プレイヤー名・スキル名をそのまま埋め込むため）
function ddEscape(s) {
    return String(s === null || s === undefined ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function ddNum(n) {
    return Math.round(Number(n) || 0).toLocaleString();
}

// ability + timestamp から enemyEvents の該当行を引く（完全一致→最近傍）
function ddFindEvent(actionName, actionTime) {
    const events = window.enemyEvents || [];
    let ev = events.find(e => e.ability === actionName && e.timestamp === actionTime);
    if (ev) return ev;
    let bestDiff = Infinity;
    for (const e of events) {
        if (e.ability !== actionName) continue;
        const d = Math.abs((e.timestamp || 0) - actionTime);
        if (d < bestDiff) { bestDiff = d; ev = e; }
    }
    return ev;
}

// 軽減は乗算で重なる。適用順に「その時点の残ダメージ × 軽減率」を割り当てると、
// 各スキルの取り分の合計＝全体の軽減量にちょうど一致する（順序依存なので軽減率降順で固定）。
function ddMitChain(base, mits) {
    const sorted = mits.slice().sort((a, b) => b.m - a.m);
    let running = base;
    const rows = [];
    for (const s of sorted) {
        const saved = running * s.m;
        rows.push(Object.assign({}, s, { saved: saved }));
        running -= saved;
    }
    return { rows: rows, theoretical: running };
}

// 同名スキルが複数人ぶん立っていたことを示すバッジ。FFXIVでは同名効果は重複しないので、
// 軽減率は1つぶんしか掛けていないことを明示する。
function ddStackBadge(e) {
    if (!e.stackCount || e.stackCount <= 1) return '';
    return `<span class="ml-1 px-1 rounded bg-gray-700 text-gray-400 text-[10px] align-middle"
        title="同名の効果は重複しないため、${e.stackCount}人ぶん発動していても軽減は1つぶんだけ計上している">×${e.stackCount} 重複</span>`;
}

function openDamageDetail(actionName, actionTime) {
    const modal = document.getElementById('damageDetailModal');
    const title = document.getElementById('damageDetailTitle');
    const content = document.getElementById('damageDetailContent');

    const playerDetails = window.playerDetails || {};
    const mitCols = window.mitCols || {};
    const shortName = (n) => (n ? String(n).split(' ')[0] : n);

    const ev = ddFindEvent(actionName, actionTime);
    if (!ev) {
        title.innerText = actionName;
        content.innerHTML = '<div class="p-6 text-center text-gray-500">この被弾のデータが見つかりませんでした</div>';
        modal.classList.remove('hidden');
        return;
    }

    const relSec = Math.floor(ev.rel_time || 0);
    const timeStr = String(Math.floor(relSec / 60)).padStart(2, '0') + ':' + String(relSec % 60).padStart(2, '0');
    title.innerHTML = `<span class="text-gray-400 mr-2 font-mono">${timeStr}</span>${ddEscape(actionName)}<span class="text-gray-500 text-sm ml-2">の被弾内訳</span>`;

    // ---- 被弾者ごとの値 ----
    // raw       素のダメージ（軽減前・バリア吸収前）
    // mitigated 軽減後のダメージ（バリアが吸う前）
    // absorbed  バリアが吸った量
    // hit       実被弾（HPに向かった量＝HP減＋オーバーキル）
    // raw −軽減−> mitigated −バリア−> hit  と一直線につながる
    const players = Object.entries(ev.players || {}).map(([name, d]) => {
        const hit = Number(d.amount) || 0;
        const absorbed = Number(d.absorbed) || 0;
        const mitigated = (d.mitigated !== undefined) ? (Number(d.mitigated) || 0) : (hit + absorbed);
        let raw = (d.unmit_full !== undefined) ? (Number(d.unmit_full) || 0) : 0;
        if (raw < mitigated) raw = mitigated;   // 生ダメージ情報が無いイベントは軽減0として扱う
        return {
            name: name,
            raw: raw,
            mitigated: mitigated,
            absorbed: absorbed,
            hit: hit,
            hpLost: Number(d.hp_lost) || 0,
            overkill: Number(d.overkill) || 0,
            buffs: (d.buffs || []).map(Number)
        };
    }).sort((a, b) => b.hit - a.hit);

    const rep = players[0] || null;   // 代表＝最大被弾者（Hit Dmg 列に出ている人）
    const partyAbsorbed = players.reduce((s, p) => s + p.absorbed, 0);
    const partyHit = players.reduce((s, p) => s + p.hit, 0);
    const partyRaw = players.reduce((s, p) => s + p.raw, 0);

    // ---- 発動していた軽減／バリアを収集 ----
    // cols はジョブ単位のカラム（PLD_リプライザル / WAR_リプライザル …）なので、
    // 同じスキルを2人が使うと別カラムとして2つ立つ。FFXIVでは同名の効果は重複しない
    // （リプライザル・牽制・アドル・ランパート等は何人使っても1つ分）ため、
    // 軽減量を計算する前にスキル名で必ず束ねる。
    const collected = [];
    for (const [colKey, col] of Object.entries(ev.cols || {})) {
        if (!col || !col.active) continue;
        const meta = mitCols[colKey] || {};
        const ids = (meta.ids || []).map(Number);
        const isDebuff = (meta.type === 'debuff');   // 敵に付くので被弾者のbuffsには現れない

        let targets = [];
        if (isDebuff) {
            targets = players.map(p => p.name);
        } else if (ids.length) {
            targets = players.filter(p => ids.some(id => p.buffs.includes(id))).map(p => p.name);
        }

        const kind = meta.kind || 'mit';
        collected.push({
            name: meta.name || colKey,
            icon: meta.icon || '',
            group: meta.group || '',
            kind: kind,
            isBarrier: (kind === 'barrier' || !!meta.has_barrier),
            mitPhys: Number(meta.mit_phys) || 0,
            mitMagic: Number(meta.mit_magic) || 0,
            sources: (col.sources && col.sources.length) ? col.sources.slice() : [],
            broken: !!col.is_broken,
            targets: targets,
            onRep: rep ? (isDebuff || targets.includes(rep.name)) : false,
            dupCols: 1
        });
    }

    // スキル名で束ねる（重複ぶんは使用者として並べるだけで、軽減率は1回しか掛けない）
    const byName = new Map();
    for (const e of collected) {
        const prev = byName.get(e.name);
        if (!prev) { byName.set(e.name, e); continue; }
        prev.dupCols += 1;
        prev.sources = Array.from(new Set(prev.sources.concat(e.sources)));
        prev.targets = Array.from(new Set(prev.targets.concat(e.targets)));
        prev.onRep = prev.onRep || e.onRep;
        prev.broken = prev.broken || e.broken;
        prev.isBarrier = prev.isBarrier || e.isBarrier;
        prev.mitPhys = Math.max(prev.mitPhys, e.mitPhys);
        prev.mitMagic = Math.max(prev.mitMagic, e.mitMagic);
        if (prev.group !== e.group) prev.group = '複数ジョブ';
    }

    // ---- 軽減スキルとバリアスキルに振り分け（両者は別物として扱う）----
    const mitSkills = [];      // 軽減スキル（代表被弾者に乗っていたもの）
    const mitOffTarget = [];   // 軽減スキルだが代表被弾者には乗っていなかったもの
    const barrierSkills = [];  // バリアスキル
    const specials = [];       // 無敵・生存系
    const notCounted = [];     // 軽減でもバリアでもない支援／軽減率未登録

    for (const e of byName.values()) {
        // 同名が複数立っていたことを示すバッジ用（使用者数と重複カラム数の多い方）
        e.stackCount = Math.max(e.dupCols, e.sources.length);
        if (e.kind === 'special') { specials.push(e); continue; }
        if (e.isBarrier) barrierSkills.push(e);
        if (e.mitPhys > 0 || e.mitMagic > 0) {
            (e.onRep ? mitSkills : mitOffTarget).push(e);
        } else if (!e.isBarrier) {
            notCounted.push(e);
        }
    }

    // ---- 軽減率・軽減量（代表被弾者の実測値が基準）----
    const raw = rep ? rep.raw : 0;
    const mitigated = rep ? rep.mitigated : 0;
    const absorbed = rep ? rep.absorbed : 0;
    const hit = rep ? rep.hit : 0;
    const cutByMit = raw - mitigated;                                  // 軽減が消したダメージ（実測）
    const mitRate = raw > 0 ? (cutByMit / raw) : 0;                    // 軽減率合計（実測）

    // 物理／魔法で軽減率が違うスキルがあるため、実測に近い方の解釈を採用する
    const chainPhys = ddMitChain(raw, mitSkills.filter(e => e.mitPhys > 0).map(e => ({ e: e, m: e.mitPhys / 100 })));
    const chainMagic = ddMitChain(raw, mitSkills.filter(e => e.mitMagic > 0).map(e => ({ e: e, m: e.mitMagic / 100 })));
    let chain, dmgType;
    if (Math.abs(chainPhys.theoretical - mitigated) < Math.abs(chainMagic.theoretical - mitigated)) {
        chain = chainPhys; dmgType = '物理';
    } else {
        chain = chainMagic; dmgType = '魔法';
    }
    const typeAmbiguous = mitSkills.every(e => e.mitPhys === e.mitMagic);
    const residual = chain.theoretical - mitigated;   // ＋なら未計上の軽減あり、−なら見積り過大

    let html = '';

    // ================= ① 最終結果（素→軽減→バリア→実被弾）=================
    const repIcon = rep && playerDetails[rep.name] && playerDetails[rep.name].icon
        ? `<img src="${playerDetails[rep.name].icon}" class="w-5 h-5 inline-block rounded-sm mr-1 align-middle">` : '';
    const repJob = rep && playerDetails[rep.name] ? (playerDetails[rep.name].job || '') : '';

    html += `
    <div>
        <div class="text-xs text-gray-400 mb-2">
            基準 ${repIcon}<span class="text-white font-bold">${ddEscape(rep ? shortName(rep.name) : '-')}</span>
            <span class="text-gray-500 ml-1">${ddEscape(repJob)}</span>
            <span class="text-gray-600 ml-1">（最大被弾者）</span>
            <span class="ml-2 text-gray-500">／ 被弾者 ${players.length}人</span>
            ${(ev.hits || 1) > 1 ? `<span class="ml-2 text-yellow-600/90">※ ${ev.hits}ヒット合算</span>` : ''}
        </div>

        <div class="flex flex-wrap items-stretch gap-1 text-center">
            <div class="flex-1 min-w-[8rem] rounded border border-gray-600 bg-gray-900/70 p-2">
                <div class="text-[11px] text-gray-500">素のダメージ</div>
                <div class="text-xl font-mono text-gray-100">${ddNum(raw)}</div>
                <div class="text-[10px] text-gray-600">軽減・バリア適用前</div>
            </div>
            <div class="flex items-center text-gray-600 text-xl px-1">−</div>
            <div class="flex-1 min-w-[8rem] rounded border border-green-700/60 bg-green-900/20 p-2">
                <div class="text-[11px] text-gray-400">軽減（合計 ${(mitRate * 100).toFixed(1)}%）</div>
                <div class="text-xl font-mono text-green-400">${ddNum(cutByMit)}</div>
                <div class="text-[10px] text-green-600">軽減スキル ${mitSkills.length}種</div>
            </div>
            <div class="flex items-center text-gray-600 text-xl px-1">−</div>
            <div class="flex-1 min-w-[8rem] rounded border border-cyan-700/60 bg-cyan-900/20 p-2">
                <div class="text-[11px] text-gray-400">バリア吸収（合計）</div>
                <div class="text-xl font-mono text-cyan-300">${ddNum(absorbed)}</div>
                <div class="text-[10px] text-cyan-600">バリアスキル ${barrierSkills.length}種</div>
            </div>
            <div class="flex items-center text-gray-600 text-xl px-1">＝</div>
            <div class="flex-1 min-w-[8rem] rounded border-2 border-orange-600 bg-orange-900/25 p-2">
                <div class="text-[11px] text-gray-300">実被弾ダメージ</div>
                <div class="text-xl font-mono text-orange-300 font-bold">${ddNum(hit)}</div>
                <div class="text-[10px] text-gray-500">HPに向かった量</div>
            </div>
        </div>

        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-gray-500">
            <span class="font-mono">${ddNum(raw)} − 軽減 ${ddNum(cutByMit)} = 軽減後 ${ddNum(mitigated)} − バリア ${ddNum(absorbed)} = 実被弾 ${ddNum(hit)}</span>
            <span>HP減 <span class="font-mono text-gray-400">${ddNum(rep ? rep.hpLost : 0)}</span></span>
            ${(rep && rep.overkill > 0) ? `<span class="text-red-400">オーバーキル <span class="font-mono">${ddNum(rep.overkill)}</span></span>` : ''}
            ${players.length > 1 ? `<span class="text-gray-600">｜パーティ全体: 素 ${ddNum(partyRaw)} / バリア ${ddNum(partyAbsorbed)} / 実被弾 ${ddNum(partyHit)}</span>` : ''}
        </div>
    </div>`;

    if (specials.length) {
        html += `<div class="rounded border border-yellow-700/50 bg-yellow-900/10 p-2 text-xs text-yellow-300">
            ⚠ 無敵・生存系が発動中: ${specials.map(e => ddEscape(e.name)).join(' / ')}（軽減率としては計上していません）
        </div>`;
    }

    // ================= ② 軽減スキル =================
    html += `
    <div>
        <div class="flex items-baseline gap-2 mb-1">
            <h4 class="text-sm font-bold text-green-300">🛡️ 軽減スキル<span class="text-gray-500 font-normal ml-1">（ダメージそのものを減らす）</span></h4>
            ${!typeAmbiguous ? `<span class="text-[11px] text-gray-500">実測との一致から <span class="text-gray-300">${dmgType}ダメージ</span>として計算</span>` : ''}
        </div>
        <table class="w-full text-left border-collapse text-sm text-gray-300">
            <thead>
                <tr class="border-b border-gray-700 text-gray-400 text-[11px] uppercase tracking-wider">
                    <th class="p-2">スキル</th>
                    <th class="p-2">使用者</th>
                    <th class="p-2 text-right">軽減率</th>
                    <th class="p-2 text-right">軽減量</th>
                    <th class="p-2">適用範囲</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-700/70">`;

    const hasResidual = Math.abs(residual) >= Math.max(1, raw * 0.005);
    if (chain.rows.length === 0 && !hasResidual) {
        html += `<tr><td colspan="5" class="p-4 text-center text-gray-500">この被弾に効いていた軽減スキルはありません</td></tr>`;
    } else {
        for (const row of chain.rows) {
            const e = row.e;
            const icon = e.icon
                ? `<img src="/icons/abilities/${e.icon}" class="w-6 h-6 object-contain rounded-sm border border-gray-600 inline-block mr-2 align-middle">` : '';
            const srcHtml = e.sources.length
                ? e.sources.map(sv => {
                    const pd = playerDetails[sv] || {};
                    const pi = pd.icon ? `<img src="${pd.icon}" class="w-5 h-5 inline-block rounded-sm mr-1 align-middle">` : '';
                    return `${pi}<span class="text-white">${ddEscape(shortName(sv))}</span>`;
                }).join('<span class="text-gray-600 mx-1">/</span>')
                : '<span class="text-gray-500 text-xs">（効果のみ検出）</span>';
            const tgtHtml = (players.length > 0 && e.targets.length >= players.length)
                ? `<span class="text-cyan-300">被弾者全員</span>`
                : `<span class="text-cyan-300">${e.targets.length}/${players.length}人</span>`;
            html += `
                <tr class="hover:bg-gray-700/40">
                    <td class="p-2">${icon}<span class="text-gray-200">${ddEscape(e.name)}</span>${e.broken ? ' <span class="text-red-400 text-xs" title="効果が途中で切れた">💔</span>' : ''}
                        ${ddStackBadge(e)}
                        <span class="text-gray-600 text-[11px] ml-1">${ddEscape(e.group)}</span></td>
                    <td class="p-2">${srcHtml}</td>
                    <td class="p-2 text-right font-mono text-green-400">${Math.round(row.m * 100)}%</td>
                    <td class="p-2 text-right font-mono text-green-300">${ddNum(row.saved)}</td>
                    <td class="p-2 text-xs">${tgtHtml}</td>
                </tr>`;
        }
        if (hasResidual) {
            const isPlus = residual > 0;
            html += `
                <tr class="bg-gray-900/40">
                    <td class="p-2 text-gray-400">${isPlus ? '未計上の軽減' : '見積り超過'}
                        <span class="text-gray-600 text-[11px] ml-1">（軽減率テーブル未登録のスキルやダメージ上昇デバフによる差）</span></td>
                    <td class="p-2 text-gray-600">-</td>
                    <td class="p-2 text-right font-mono text-gray-500">-</td>
                    <td class="p-2 text-right font-mono ${isPlus ? 'text-green-300' : 'text-red-400'}">${isPlus ? '' : '−'}${ddNum(Math.abs(residual))}</td>
                    <td class="p-2 text-xs text-gray-600">実測との差</td>
                </tr>`;
        }
    }
    html += `
            <tr class="border-t-2 border-gray-600 bg-gray-900/60 font-bold">
                <td class="p-2 text-white" colspan="2">軽減率合計（実測）</td>
                <td class="p-2 text-right font-mono text-green-400">${(mitRate * 100).toFixed(1)}%</td>
                <td class="p-2 text-right font-mono text-green-400">${ddNum(cutByMit)}</td>
                <td class="p-2 text-xs text-gray-500">${ddNum(raw)} → ${ddNum(mitigated)}</td>
            </tr>
        </tbody></table>`;

    if (mitOffTarget.length) {
        html += `<div class="mt-2 text-[11px] text-gray-500">
            発動中だが ${ddEscape(rep ? shortName(rep.name) : '')} には乗っていなかった軽減:
            ${mitOffTarget.map(e => `<span class="inline-block px-1.5 py-0.5 mx-0.5 rounded bg-gray-700/50 text-gray-400">${ddEscape(e.name)}</span>`).join('')}
        </div>`;
    }
    if (notCounted.length) {
        html += `<div class="mt-1 text-[11px] text-gray-600">
            軽減でもバリアでもない支援（被弾ダメージは減らない）:
            ${notCounted.map(e => `<span class="inline-block px-1.5 py-0.5 mx-0.5 rounded bg-gray-800 text-gray-500">${ddEscape(e.name)}</span>`).join('')}
        </div>`;
    }
    html += `</div>`;

    // ================= ③ バリアスキル =================
    html += `
    <div>
        <h4 class="text-sm font-bold text-cyan-300 mb-1">💠 バリアスキル<span class="text-gray-500 font-normal ml-1">（軽減後のダメージを肩代わりして吸収する）</span></h4>
        <table class="w-full text-left border-collapse text-sm text-gray-300">
            <thead>
                <tr class="border-b border-gray-700 text-gray-400 text-[11px] uppercase tracking-wider">
                    <th class="p-2">スキル</th>
                    <th class="p-2">使用者</th>
                    <th class="p-2">適用範囲</th>
                    <th class="p-2 text-right">状態</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-700/70">`;
    if (barrierSkills.length === 0) {
        html += `<tr><td colspan="4" class="p-4 text-center text-gray-500">この被弾時に乗っていたバリアはありません</td></tr>`;
    } else {
        for (const e of barrierSkills) {
            const icon = e.icon
                ? `<img src="/icons/abilities/${e.icon}" class="w-6 h-6 object-contain rounded-sm border border-gray-600 inline-block mr-2 align-middle">` : '';
            const srcHtml = e.sources.length
                ? e.sources.map(sv => {
                    const pd = playerDetails[sv] || {};
                    const pi = pd.icon ? `<img src="${pd.icon}" class="w-5 h-5 inline-block rounded-sm mr-1 align-middle">` : '';
                    return `${pi}<span class="text-white">${ddEscape(shortName(sv))}</span>`;
                }).join('<span class="text-gray-600 mx-1">/</span>')
                : '<span class="text-gray-500 text-xs">（効果のみ検出）</span>';
            const tgtHtml = (players.length > 0 && e.targets.length >= players.length)
                ? `<span class="text-cyan-300">被弾者全員</span>`
                : `<span class="text-cyan-300">${e.targets.length}/${players.length}人</span>`;
            html += `
                <tr class="hover:bg-gray-700/40">
                    <td class="p-2">${icon}<span class="text-gray-200">${ddEscape(e.name)}</span>
                        ${ddStackBadge(e)}
                        <span class="text-gray-600 text-[11px] ml-1">${ddEscape(e.group)}</span></td>
                    <td class="p-2">${srcHtml}</td>
                    <td class="p-2 text-xs">${tgtHtml}</td>
                    <td class="p-2 text-right text-xs">${e.broken ? '<span class="text-red-400">💔 割れた</span>' : '<span class="text-gray-500">維持</span>'}</td>
                </tr>`;
        }
    }
    html += `
            <tr class="border-t-2 border-gray-600 bg-gray-900/60 font-bold">
                <td class="p-2 text-white" colspan="3">バリア吸収 合計（実測）</td>
                <td class="p-2 text-right font-mono text-cyan-300">${ddNum(absorbed)}</td>
            </tr>
        </tbody></table>
        <div class="mt-1 text-[11px] text-gray-600">※ 吸収量はスキルごとの内訳がログに残らないため合算のみ。バリアは軽減後のダメージから引かれるので、軽減率には含まれません。</div>
    </div>`;

    // ================= ④ 被弾者ごとの内訳 =================
    html += `
    <div>
        <h4 class="text-sm font-bold text-white mb-1">👥 被弾者ごとの内訳</h4>
        <table class="w-full text-left border-collapse text-sm text-gray-300">
            <thead>
                <tr class="border-b border-gray-700 text-gray-400 text-[11px] uppercase tracking-wider">
                    <th class="p-2">プレイヤー</th>
                    <th class="p-2 text-right">素のダメージ</th>
                    <th class="p-2 text-right">軽減率</th>
                    <th class="p-2 text-right">軽減量</th>
                    <th class="p-2 text-right">バリア吸収</th>
                    <th class="p-2 text-right">実被弾</th>
                    <th class="p-2 text-right">HP減 / 過剰</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-700/70">`;
    for (const p of players) {
        const pd = playerDetails[p.name] || {};
        const pi = pd.icon ? `<img src="${pd.icon}" class="w-5 h-5 inline-block rounded-sm mr-1 align-middle">` : '';
        const cut = p.raw - p.mitigated;
        const rate = p.raw > 0 ? (cut / p.raw * 100) : 0;
        html += `
            <tr class="hover:bg-gray-700/40 ${rep && p.name === rep.name ? 'bg-gray-900/40' : ''}">
                <td class="p-2">${pi}<span class="text-white">${ddEscape(shortName(p.name))}</span>
                    <span class="text-gray-500 text-[11px] ml-1">${ddEscape(pd.job || '')}</span></td>
                <td class="p-2 text-right font-mono text-gray-300">${ddNum(p.raw)}</td>
                <td class="p-2 text-right font-mono ${rate > 0 ? 'text-green-400' : 'text-red-400'}">${rate > 0 ? rate.toFixed(1) + '%' : 'なし'}</td>
                <td class="p-2 text-right font-mono ${cut > 0 ? 'text-green-300' : 'text-gray-600'}">${cut > 0 ? ddNum(cut) : '-'}</td>
                <td class="p-2 text-right font-mono ${p.absorbed > 0 ? 'text-cyan-300' : 'text-gray-600'}">${p.absorbed > 0 ? ddNum(p.absorbed) : '-'}</td>
                <td class="p-2 text-right font-mono text-orange-300 font-bold">${ddNum(p.hit)}</td>
                <td class="p-2 text-right font-mono text-gray-400">${ddNum(p.hpLost)}${p.overkill > 0 ? ` <span class="text-red-500">/ ${ddNum(p.overkill)}☠</span>` : ''}</td>
            </tr>`;
    }
    if (players.length === 0) {
        html += `<tr><td colspan="7" class="p-4 text-center text-gray-500">被弾者データがありません</td></tr>`;
    }
    html += `</tbody></table></div>`;

    html += `<div class="text-[11px] text-gray-600 leading-relaxed border-t border-gray-700 pt-2">
        ※ 素のダメージ・軽減率合計・バリア吸収・実被弾はすべてFFLogsの実測値。
        軽減スキルごとの「軽減量」だけは軽減率テーブルからの推定で、軽減は乗算で重なるため軽減率の高い順に按分している（合計は実測に一致するよう差分行で調整）。
        同名の軽減（リプライザル・牽制・ランパート等）は何人が使っても重複しないため、1つぶんだけ計上している。
    </div>`;

    content.innerHTML = html;
    modal.classList.remove('hidden');
}

function closeDamageDetailModal() {
    document.getElementById('damageDetailModal').classList.add('hidden');
}

// Esc でモーダルを閉じる
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    const m = document.getElementById('damageDetailModal');
    if (m && !m.classList.contains('hidden')) closeDamageDetailModal();
});
