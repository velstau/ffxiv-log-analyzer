// アイコンの配信元（サブパス配信に対応するため Blade から受け取る）
const ICON_BASE = window.ICON_BASE || "/icons";

function togglePlayers() {
    const hidden = document.body.classList.toggle("hide-players");
    const button = document.getElementById('toggle-players');
    if (button) { button.setAttribute('aria-pressed', String(!hidden)); button.classList.toggle('active', !hidden); }
    window.dispatchEvent(new Event('timeline:layout'));
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
            btn.classList.toggle('active', active);
            btn.setAttribute('aria-selected', String(active));
        }
    }
    window.dispatchEvent(new Event('timeline:layout'));
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
        div.className = 'legacy-flex legacy-items-center legacy-space-x-2 legacy-col-span-full bg-body-tertiary legacy-p-2 rounded legacy-mb-1';
        div.innerHTML = `
            <input type="checkbox" id="${cat.id}" 
                class="form-check-input bg-body-tertiary border-secondary text-green rounded legacy-h-4 legacy-w-4"
                ${cat.checked ? 'checked' : ''}>
            <label for="${cat.id}" class="text-base text-white fw-bold legacy-cursor-pointer legacy-select-none">
                ${cat.label}
            </label>
        `;
        list.appendChild(div);
    });

    // Separator
    const sep = document.createElement('div');
    sep.className = 'legacy-col-span-full legacy-h-px bg-body-tertiary legacy-my-2';
    list.appendChild(sep);

    // 2. Individual Actions
    actions.forEach(action => {
        const isHidden = hiddenActions.has(action);
        const div = document.createElement('div');
        div.className = 'legacy-flex legacy-items-center legacy-space-x-2';
        div.innerHTML = `
            <input type="checkbox" id="act_${action}" value="${action}" 
                class="action-checkbox form-check-input bg-body-tertiary border-secondary text-blue rounded legacy-h-4 legacy-w-4"
                ${!isHidden ? 'checked' : ''}>
            <label for="act_${action}" class="text-base text-body legacy-truncate legacy-cursor-pointer legacy-select-none" title="${action}">
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

// Use the same renderer as the comparison page; supports Tabler's modern colors.
async function captureTimelineElement(element, filename) {
    const host = document.createElement('div');
    host.style.cssText = 'position:fixed;left:-100000px;top:0;pointer-events:none';
    const clone = element.cloneNode(true);
    clone.style.cssText += ';position:relative;left:0;top:0;max-height:none;height:auto;overflow:visible';
    clone.style.width = Math.max(element.clientWidth, element.scrollWidth) + 'px';
    clone.querySelectorAll('th,.c-phase,.c-time,.c-action,.c-base,.c-hit').forEach(el => el.style.setProperty('position', 'static', 'important'));
    clone.querySelectorAll('tr').forEach(row => { row.style.contentVisibility = 'visible'; });
    clone.querySelectorAll('img').forEach(img => {
        const url = new URL(img.src, location.href);
        if (url.origin !== location.origin && ['assets.rpglogs.com', 'raw.githubusercontent.com'].includes(url.hostname)) {
            img.src = window.IMAGE_PROXY_BASE + '?url=' + encodeURIComponent(url.href);
        }
    });
    host.appendChild(clone); document.body.appendChild(host);
    try {
        const dataUrl = await modernScreenshot.domToPng(clone, { scale: 1, backgroundColor: getComputedStyle(document.body).backgroundColor });
        const link = document.createElement('a'); link.href = dataUrl; link.download = filename; link.click();
    } catch (error) {
        console.error('Screenshot failed:', error);
        alert('画像の保存に失敗しました: ' + error.message);
    } finally { host.remove(); }
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
        iconHtml = `<img src="${pDetail.icon}" class="legacy-w-6 legacy-h-6 legacy-mr-2 legacy-inline-block rounded">`;
    }
    title.innerHTML =
        `${playerName} <span class="text-base text-secondary fw-normal">(${pDetail.job || 'Unknown'})</span>`;

    // Build Content
    if (pDetail && pDetail.icon) {
        iconHtml = `<img src="${pDetail.icon}" class="legacy-w-6 legacy-h-6 legacy-mr-2 legacy-inline-block rounded">`;
    }
    title.innerHTML =
        `${pDetail.name} <span class="text-base text-secondary fw-normal">&nbsp;[${pDetail.job}]</span>`;

    // Clear Body
    content.innerHTML = '';

    // Add Disclaimer
    content.innerHTML += '<div class="text-base text-secondary legacy-mb-2 legacy-pl-2 legacy-border-l-2 border-secondary">※ 戦闘開始前（0:00以前）に使用されたスキルは表示されません。</div>';

    if (timeline.length === 0) {
        content.innerHTML += '<div class="text-secondary text-center legacy-py-4">No major cooldowns used.</div>';
    } else {
        let html = `
            <table class="legacy-w-full text-start legacy-border-collapse text-base text-body">
                <thead>
                    <tr class="legacy-border-b border-secondary text-secondary font-monospace text-base uppercase tracking-wider">
                        <th class="legacy-p-2 legacy-w-20 text-end">Time</th>
                        <th class="legacy-p-2 text-start">Action</th>
                        <th class="legacy-p-2 legacy-pl-4">Skill</th>
                        <th class="legacy-p-2 legacy-pl-4">対象</th>
                    </tr>
                </thead>
                <tbody class="  bg-surface">
        `;

        timeline.forEach(event => {
            const timeStr = formatTime(event.rel_time);
            const iconUrl = event.icon ?
                `${ICON_BASE}/abilities/${event.icon}` :
                null;

            const iconHtml = iconUrl ?
                `<img src="${iconUrl}" class="legacy-w-6 legacy-h-6 legacy-object-contain rounded border ${event.broken ? "border-red border-2" : "border-secondary"}" onerror="this.style.display='none'">` :
                '<div class="legacy-w-6 legacy-h-6"></div>';

            // 対象（ターゲット）表示。自分自身への使用は「自分」、他者対象は名前を強調
            const targetHtml = event.target ?
                (event.self_target ?
                    `<span class="text-secondary text-base">自分</span>` :
                    `<span class="text-cyan fw-medium">${event.target}</span>`) :
                `<span class="text-secondary">-</span>`;

            html += `
                <tr class=" ">
                    <td class="legacy-p-2 text-end font-monospace text-secondary">${timeStr}</td>
                    <td class="legacy-p-2 text-start text-body text-base align-middle">
                        ${event.enemy_action || "-"}
                    </td>
                    <td class="legacy-p-2 legacy-pl-4 align-middle legacy-flex legacy-items-center legacy-gap-2">
                        ${iconHtml}
                        <span class="text-body fw-medium">${event.name}${event.broken ? " <span title='Barrier Broken' class='text-red text-base'>💔</span>" : ""}</span>
                    </td>
                    <td class="legacy-p-2 legacy-pl-4 align-middle">${targetHtml}</td>
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
        <table class="legacy-w-full text-start legacy-border-collapse text-base text-body">
            <thead>
                <tr class="legacy-border-b border-secondary text-secondary text-base uppercase tracking-wider">
                    <th class="legacy-p-2">軽減スキル</th>
                    <th class="legacy-p-2">使用者</th>
                    <th class="legacy-p-2">対象（誰に入っていたか）</th>
                    <th class="legacy-p-2 text-end">残り時間</th>
                </tr>
            </thead>
            <tbody class="  bg-surface">
    `;

    let foundAny = false;

    if (ev && ev.cols) {
        for (const [colKey, col] of Object.entries(ev.cols)) {
            if (!col || !col.active) continue;
            foundAny = true;

            const meta = mitCols[colKey] || {};
            const displayName = meta.name || colKey;
            const skillIcon = meta.icon
                ? `<img src="${ICON_BASE}/abilities/${meta.icon}" class="legacy-w-6 legacy-h-6 legacy-object-contain rounded border border-secondary legacy-inline-block legacy-mr-2 align-middle">`
                : '';
            const brokenTag = col.is_broken ? " <span title='バリア消滅' class='text-red text-base'>💔</span>" : "";

            const sources = (col.sources && col.sources.length) ? col.sources : null;

            // 残り時間：サーバ算出(col.remaining=実際に剥がれるまで)を優先、無ければ詠唱ログから補完
            let rem = (typeof col.remaining === 'number') ? col.remaining : null;
            if (rem === null && sources) {
                for (const s of sources) {
                    const r = remainingFor(s, displayName);
                    if (r !== null && (rem === null || r < rem)) rem = r;
                }
            }
            let remStr = '<span class="text-secondary">-</span>';
            if (rem !== null) {
                const c = rem < 2 ? 'text-yellow' : 'text-green';
                remStr = `<span class="font-monospace ${c}">${rem.toFixed(1)}s</span>`;
            }

            let srcHtml;
            if (sources) {
                srcHtml = sources.map(s => {
                    const pd = playerDetails[s] || {};
                    const icon = pd.icon ? `<img src="${pd.icon}" class="legacy-w-5 legacy-h-5 legacy-inline-block rounded legacy-mr-1 align-middle">` : '';
                    const job = pd.job ? `<span class="text-secondary text-base legacy-ml-1">${pd.job}</span>` : '';
                    return `${icon}<span class="text-white fw-medium">${shortName(s)}</span>${job}`;
                }).join('<span class="text-secondary legacy-mx-1">/</span>');
            } else {
                srcHtml = `<span class="text-secondary text-base">（効果のみ検出）</span>`;
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
                tgtHtml = `<span class="text-secondary">-</span>`;
            } else if (partySize > 0 && targets.length >= partySize) {
                tgtHtml = `<span class="text-cyan fw-medium">全員</span>`;
            } else {
                // 対象を実名で列挙（バリア等が誰に入っていたか分かるように）
                tgtHtml = targets.map(t => {
                    const pd = playerDetails[t] || {};
                    const icon = pd.icon ? `<img src="${pd.icon}" class="legacy-w-5 legacy-h-5 legacy-inline-block rounded legacy-mr-1 align-middle">` : '';
                    return `${icon}<span class="text-cyan">${shortName(t)}</span>`;
                }).join('<span class="text-secondary legacy-mx-1">/</span>');
            }

            html += `
                <tr class="">
                    <td class="legacy-p-2 legacy-flex legacy-items-center">${skillIcon}<span class="text-body">${displayName}${brokenTag}</span></td>
                    <td class="legacy-p-2">${srcHtml}</td>
                    <td class="legacy-p-2">${tgtHtml}</td>
                    <td class="legacy-p-2 text-end">${remStr}</td>
                </tr>
            `;
        }
    }

    if (!foundAny) {
        html += `<tr><td colspan="4" class="legacy-p-4 text-center text-secondary">この被弾に効いていた軽減はありません</td></tr>`;
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
    if (!element) { alert('Element not found'); return; }
    return captureTimelineElement(element, filename);
}

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
    return `<span class="legacy-ml-1 legacy-px-1 rounded bg-body-tertiary text-secondary text-base align-middle"
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
        content.innerHTML = '<div class="legacy-p-6 text-center text-secondary">この被弾のデータが見つかりませんでした</div>';
        modal.classList.remove('hidden');
        return;
    }

    const relSec = Math.floor(ev.rel_time || 0);
    const timeStr = String(Math.floor(relSec / 60)).padStart(2, '0') + ':' + String(relSec % 60).padStart(2, '0');
    title.innerHTML = `<span class="text-secondary legacy-mr-2 font-monospace">${timeStr}</span>${ddEscape(actionName)}<span class="text-secondary text-base legacy-ml-2">の被弾内訳</span>`;

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
        ? `<img src="${playerDetails[rep.name].icon}" class="legacy-w-5 legacy-h-5 legacy-inline-block rounded legacy-mr-1 align-middle">` : '';
    const repJob = rep && playerDetails[rep.name] ? (playerDetails[rep.name].job || '') : '';

    html += `
    <div>
        <div class="text-base text-secondary legacy-mb-2">
            基準 ${repIcon}<span class="text-white fw-bold">${ddEscape(rep ? shortName(rep.name) : '-')}</span>
            <span class="text-secondary legacy-ml-1">${ddEscape(repJob)}</span>
            <span class="text-secondary legacy-ml-1">（最大被弾者）</span>
            <span class="legacy-ml-2 text-secondary">／ 被弾者 ${players.length}人</span>
            ${(ev.hits || 1) > 1 ? `<span class="legacy-ml-2 text-yellow">※ ${ev.hits}ヒット合算</span>` : ''}
        </div>

        <div class="legacy-flex legacy-flex-wrap legacy-items-stretch legacy-gap-1 text-center">
            <div class="legacy-flex-1 legacy-min-w-[8rem] rounded border border-secondary bg-body legacy-p-2">
                <div class="text-base text-secondary">素のダメージ</div>
                <div class="fs-2 font-monospace text-body">${ddNum(raw)}</div>
                <div class="text-base text-secondary">軽減・バリア適用前</div>
            </div>
            <div class="legacy-flex legacy-items-center text-secondary fs-2 legacy-px-1">−</div>
            <div class="legacy-flex-1 legacy-min-w-[8rem] rounded border border-green bg-green-lt legacy-p-2">
                <div class="text-base text-secondary">軽減（合計 ${(mitRate * 100).toFixed(1)}%）</div>
                <div class="fs-2 font-monospace text-green">${ddNum(cutByMit)}</div>
                <div class="text-base text-green">軽減スキル ${mitSkills.length}種</div>
            </div>
            <div class="legacy-flex legacy-items-center text-secondary fs-2 legacy-px-1">−</div>
            <div class="legacy-flex-1 legacy-min-w-[8rem] rounded border border-cyan bg-cyan-lt legacy-p-2">
                <div class="text-base text-secondary">バリア吸収（合計）</div>
                <div class="fs-2 font-monospace text-cyan">${ddNum(absorbed)}</div>
                <div class="text-base text-cyan">バリアスキル ${barrierSkills.length}種</div>
            </div>
            <div class="legacy-flex legacy-items-center text-secondary fs-2 legacy-px-1">＝</div>
            <div class="legacy-flex-1 legacy-min-w-[8rem] rounded border-2 border-orange bg-orange-lt legacy-p-2">
                <div class="text-base text-body">実被弾ダメージ</div>
                <div class="fs-2 font-monospace text-orange fw-bold">${ddNum(hit)}</div>
                <div class="text-base text-secondary">HPに向かった量</div>
            </div>
        </div>

        <div class="legacy-mt-2 legacy-flex legacy-flex-wrap legacy-gap-x-4 legacy-gap-y-1 text-base text-secondary">
            <span class="font-monospace">${ddNum(raw)} − 軽減 ${ddNum(cutByMit)} = 軽減後 ${ddNum(mitigated)} − バリア ${ddNum(absorbed)} = 実被弾 ${ddNum(hit)}</span>
            <span>HP減 <span class="font-monospace text-secondary">${ddNum(rep ? rep.hpLost : 0)}</span></span>
            ${(rep && rep.overkill > 0) ? `<span class="text-red">オーバーキル <span class="font-monospace">${ddNum(rep.overkill)}</span></span>` : ''}
            ${players.length > 1 ? `<span class="text-secondary">｜パーティ全体: 素 ${ddNum(partyRaw)} / バリア ${ddNum(partyAbsorbed)} / 実被弾 ${ddNum(partyHit)}</span>` : ''}
        </div>
    </div>`;

    if (specials.length) {
        html += `<div class="rounded border border-yellow bg-yellow-lt legacy-p-2 text-base text-yellow">
            ⚠ 無敵・生存系が発動中: ${specials.map(e => ddEscape(e.name)).join(' / ')}（軽減率としては計上していません）
        </div>`;
    }

    // ================= ② 軽減スキル =================
    html += `
    <div>
        <div class="legacy-flex legacy-items-baseline legacy-gap-2 legacy-mb-1">
            <h4 class="text-base fw-bold text-green">🛡️ 軽減スキル<span class="text-secondary fw-normal legacy-ml-1">（ダメージそのものを減らす）</span></h4>
            ${!typeAmbiguous ? `<span class="text-base text-secondary">実測との一致から <span class="text-body">${dmgType}ダメージ</span>として計算</span>` : ''}
        </div>
        <table class="legacy-w-full text-start legacy-border-collapse text-base text-body">
            <thead>
                <tr class="legacy-border-b border-secondary text-secondary text-base uppercase tracking-wider">
                    <th class="legacy-p-2">スキル</th>
                    <th class="legacy-p-2">使用者</th>
                    <th class="legacy-p-2 text-end">軽減率</th>
                    <th class="legacy-p-2 text-end">軽減量</th>
                    <th class="legacy-p-2">適用範囲</th>
                </tr>
            </thead>
            <tbody class=" ">`;

    const hasResidual = Math.abs(residual) >= Math.max(1, raw * 0.005);
    if (chain.rows.length === 0 && !hasResidual) {
        html += `<tr><td colspan="5" class="legacy-p-4 text-center text-secondary">この被弾に効いていた軽減スキルはありません</td></tr>`;
    } else {
        for (const row of chain.rows) {
            const e = row.e;
            const icon = e.icon
                ? `<img src="${ICON_BASE}/abilities/${e.icon}" class="legacy-w-6 legacy-h-6 legacy-object-contain rounded border border-secondary legacy-inline-block legacy-mr-2 align-middle">` : '';
            const srcHtml = e.sources.length
                ? e.sources.map(sv => {
                    const pd = playerDetails[sv] || {};
                    const pi = pd.icon ? `<img src="${pd.icon}" class="legacy-w-5 legacy-h-5 legacy-inline-block rounded legacy-mr-1 align-middle">` : '';
                    return `${pi}<span class="text-white">${ddEscape(shortName(sv))}</span>`;
                }).join('<span class="text-secondary legacy-mx-1">/</span>')
                : '<span class="text-secondary text-base">（効果のみ検出）</span>';
            const tgtHtml = (players.length > 0 && e.targets.length >= players.length)
                ? `<span class="text-cyan">被弾者全員</span>`
                : `<span class="text-cyan">${e.targets.length}/${players.length}人</span>`;
            html += `
                <tr class="">
                    <td class="legacy-p-2">${icon}<span class="text-body">${ddEscape(e.name)}</span>${e.broken ? ' <span class="text-red text-base" title="効果が途中で切れた">💔</span>' : ''}
                        ${ddStackBadge(e)}
                        <span class="text-secondary text-base legacy-ml-1">${ddEscape(e.group)}</span></td>
                    <td class="legacy-p-2">${srcHtml}</td>
                    <td class="legacy-p-2 text-end font-monospace text-green">${Math.round(row.m * 100)}%</td>
                    <td class="legacy-p-2 text-end font-monospace text-green">${ddNum(row.saved)}</td>
                    <td class="legacy-p-2 text-base">${tgtHtml}</td>
                </tr>`;
        }
        if (hasResidual) {
            const isPlus = residual > 0;
            html += `
                <tr class="bg-body">
                    <td class="legacy-p-2 text-secondary">${isPlus ? '未計上の軽減' : '見積り超過'}
                        <span class="text-secondary text-base legacy-ml-1">（軽減率テーブル未登録のスキルやダメージ上昇デバフによる差）</span></td>
                    <td class="legacy-p-2 text-secondary">-</td>
                    <td class="legacy-p-2 text-end font-monospace text-secondary">-</td>
                    <td class="legacy-p-2 text-end font-monospace ${isPlus ? 'text-green' : 'text-red'}">${isPlus ? '' : '−'}${ddNum(Math.abs(residual))}</td>
                    <td class="legacy-p-2 text-base text-secondary">実測との差</td>
                </tr>`;
        }
    }
    html += `
            <tr class="legacy-border-t-2 border-secondary bg-body fw-bold">
                <td class="legacy-p-2 text-white" colspan="2">軽減率合計（実測）</td>
                <td class="legacy-p-2 text-end font-monospace text-green">${(mitRate * 100).toFixed(1)}%</td>
                <td class="legacy-p-2 text-end font-monospace text-green">${ddNum(cutByMit)}</td>
                <td class="legacy-p-2 text-base text-secondary">${ddNum(raw)} → ${ddNum(mitigated)}</td>
            </tr>
        </tbody></table>`;

    if (mitOffTarget.length) {
        html += `<div class="legacy-mt-2 text-base text-secondary">
            発動中だが ${ddEscape(rep ? shortName(rep.name) : '')} には乗っていなかった軽減:
            ${mitOffTarget.map(e => `<span class="legacy-inline-block legacy-px-1.5 legacy-py-0.5 legacy-mx-0.5 rounded bg-body-tertiary text-secondary">${ddEscape(e.name)}</span>`).join('')}
        </div>`;
    }
    if (notCounted.length) {
        html += `<div class="legacy-mt-1 text-base text-secondary">
            軽減でもバリアでもない支援（被弾ダメージは減らない）:
            ${notCounted.map(e => `<span class="legacy-inline-block legacy-px-1.5 legacy-py-0.5 legacy-mx-0.5 rounded bg-surface text-secondary">${ddEscape(e.name)}</span>`).join('')}
        </div>`;
    }
    html += `</div>`;

    // ================= ③ バリアスキル =================
    html += `
    <div>
        <h4 class="text-base fw-bold text-cyan legacy-mb-1">💠 バリアスキル<span class="text-secondary fw-normal legacy-ml-1">（軽減後のダメージを肩代わりして吸収する）</span></h4>
        <table class="legacy-w-full text-start legacy-border-collapse text-base text-body">
            <thead>
                <tr class="legacy-border-b border-secondary text-secondary text-base uppercase tracking-wider">
                    <th class="legacy-p-2">スキル</th>
                    <th class="legacy-p-2">使用者</th>
                    <th class="legacy-p-2">適用範囲</th>
                    <th class="legacy-p-2 text-end">状態</th>
                </tr>
            </thead>
            <tbody class=" ">`;
    if (barrierSkills.length === 0) {
        html += `<tr><td colspan="4" class="legacy-p-4 text-center text-secondary">この被弾時に乗っていたバリアはありません</td></tr>`;
    } else {
        for (const e of barrierSkills) {
            const icon = e.icon
                ? `<img src="${ICON_BASE}/abilities/${e.icon}" class="legacy-w-6 legacy-h-6 legacy-object-contain rounded border border-secondary legacy-inline-block legacy-mr-2 align-middle">` : '';
            const srcHtml = e.sources.length
                ? e.sources.map(sv => {
                    const pd = playerDetails[sv] || {};
                    const pi = pd.icon ? `<img src="${pd.icon}" class="legacy-w-5 legacy-h-5 legacy-inline-block rounded legacy-mr-1 align-middle">` : '';
                    return `${pi}<span class="text-white">${ddEscape(shortName(sv))}</span>`;
                }).join('<span class="text-secondary legacy-mx-1">/</span>')
                : '<span class="text-secondary text-base">（効果のみ検出）</span>';
            const tgtHtml = (players.length > 0 && e.targets.length >= players.length)
                ? `<span class="text-cyan">被弾者全員</span>`
                : `<span class="text-cyan">${e.targets.length}/${players.length}人</span>`;
            html += `
                <tr class="">
                    <td class="legacy-p-2">${icon}<span class="text-body">${ddEscape(e.name)}</span>
                        ${ddStackBadge(e)}
                        <span class="text-secondary text-base legacy-ml-1">${ddEscape(e.group)}</span></td>
                    <td class="legacy-p-2">${srcHtml}</td>
                    <td class="legacy-p-2 text-base">${tgtHtml}</td>
                    <td class="legacy-p-2 text-end text-base">${e.broken ? '<span class="text-red">💔 割れた</span>' : '<span class="text-secondary">維持</span>'}</td>
                </tr>`;
        }
    }
    html += `
            <tr class="legacy-border-t-2 border-secondary bg-body fw-bold">
                <td class="legacy-p-2 text-white" colspan="3">バリア吸収 合計（実測）</td>
                <td class="legacy-p-2 text-end font-monospace text-cyan">${ddNum(absorbed)}</td>
            </tr>
        </tbody></table>
        <div class="legacy-mt-1 text-base text-secondary">※ 吸収量はスキルごとの内訳がログに残らないため合算のみ。バリアは軽減後のダメージから引かれるので、軽減率には含まれません。</div>
    </div>`;

    // ================= ④ 被弾者ごとの内訳 =================
    html += `
    <div>
        <h4 class="text-base fw-bold text-white legacy-mb-1">👥 被弾者ごとの内訳</h4>
        <table class="legacy-w-full text-start legacy-border-collapse text-base text-body">
            <thead>
                <tr class="legacy-border-b border-secondary text-secondary text-base uppercase tracking-wider">
                    <th class="legacy-p-2">プレイヤー</th>
                    <th class="legacy-p-2 text-end">素のダメージ</th>
                    <th class="legacy-p-2 text-end">軽減率</th>
                    <th class="legacy-p-2 text-end">軽減量</th>
                    <th class="legacy-p-2 text-end">バリア吸収</th>
                    <th class="legacy-p-2 text-end">実被弾</th>
                    <th class="legacy-p-2 text-end">HP減 / 過剰</th>
                </tr>
            </thead>
            <tbody class=" ">`;
    for (const p of players) {
        const pd = playerDetails[p.name] || {};
        const pi = pd.icon ? `<img src="${pd.icon}" class="legacy-w-5 legacy-h-5 legacy-inline-block rounded legacy-mr-1 align-middle">` : '';
        const cut = p.raw - p.mitigated;
        const rate = p.raw > 0 ? (cut / p.raw * 100) : 0;
        html += `
            <tr class=" ${rep && p.name === rep.name ? 'bg-body' : ''}">
                <td class="legacy-p-2">${pi}<span class="text-white">${ddEscape(shortName(p.name))}</span>
                    <span class="text-secondary text-base legacy-ml-1">${ddEscape(pd.job || '')}</span></td>
                <td class="legacy-p-2 text-end font-monospace text-body">${ddNum(p.raw)}</td>
                <td class="legacy-p-2 text-end font-monospace ${rate > 0 ? 'text-green' : 'text-red'}">${rate > 0 ? rate.toFixed(1) + '%' : 'なし'}</td>
                <td class="legacy-p-2 text-end font-monospace ${cut > 0 ? 'text-green' : 'text-secondary'}">${cut > 0 ? ddNum(cut) : '-'}</td>
                <td class="legacy-p-2 text-end font-monospace ${p.absorbed > 0 ? 'text-cyan' : 'text-secondary'}">${p.absorbed > 0 ? ddNum(p.absorbed) : '-'}</td>
                <td class="legacy-p-2 text-end font-monospace text-orange fw-bold">${ddNum(p.hit)}</td>
                <td class="legacy-p-2 text-end font-monospace text-secondary">${ddNum(p.hpLost)}${p.overkill > 0 ? ` <span class="text-red">/ ${ddNum(p.overkill)}☠</span>` : ''}</td>
            </tr>`;
    }
    if (players.length === 0) {
        html += `<tr><td colspan="7" class="legacy-p-4 text-center text-secondary">被弾者データがありません</td></tr>`;
    }
    html += `</tbody></table></div>`;

    html += `<div class="text-base text-secondary legacy-leading-relaxed legacy-border-t border-secondary legacy-pt-2">
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
