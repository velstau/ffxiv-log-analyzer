/* UI behavior only. Data retrieval, timeline analysis and storage remain in the existing code. */
(function () {
    'use strict';
    // Preserve keyboard access to existing clickable result cells.
    document.querySelectorAll('td[onclick], th[onclick]').forEach(function (cell) {
        cell.tabIndex = 0;
        var action = cell.getAttribute('onclick') || '';
        var detail = { openActionDetail: '技の詳細', openDamageDetail: '被ダメージの内訳', openJobTimeline: 'ジョブのスキル履歴', openPlayerTimeline: 'プレイヤーのスキル履歴' };
        var name = Object.keys(detail).find(function (key) { return action.startsWith(key + '('); });
        if (name) {
            cell.classList.add('detail-trigger');
            cell.setAttribute('aria-haspopup', 'dialog');
            var label = cell.textContent.trim().replace(/\s+/g, ' ');
            var hint = detail[name] + 'を開く';
            cell.setAttribute('aria-label', label + '：' + hint);
            cell.title = (cell.title ? cell.title + '\n' : '') + hint + '（クリック / Enter）';
            var mark = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            mark.setAttribute('viewBox', '0 0 24 24');
            mark.setAttribute('class', 'detail-mark');
            mark.setAttribute('aria-hidden', 'true');
            mark.setAttribute('focusable', 'false');
            // Tabler Icons outline/list-details, already used by this project.
            mark.innerHTML = '<path d="M13 5h8M13 9h5M13 15h8M13 19h5M3 5h6v6H3zM3 15h6v6H3z"/>';
            if (cell.tagName === 'TD') cell.appendChild(mark);
        }
        cell.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); cell.click(); }
        });
    });
    // Existing custom dialogs retain their API and content. Add focus trapping/restoration.
    document.querySelectorAll('[id$="Modal"]').forEach(function (modal) {
        var previous = null;
        var opened = false;
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.tabIndex = -1;
        function sync() {
            var visible = !modal.classList.contains('hidden');
            if (visible === opened) return;
            opened = visible;
            modal.setAttribute('aria-hidden', String(!visible));
            if (visible) {
                previous = document.activeElement;
                var first = modal.querySelector('button, input, select, [tabindex="0"]');
                (first || modal).focus();
            } else if (previous && previous.isConnected) previous.focus();
        }
        new MutationObserver(sync).observe(modal, { attributes: true, attributeFilter: ['class'] });
        modal.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                var close = modal.querySelector('button[onclick^="close"]');
                if (close) { event.preventDefault(); event.stopPropagation(); close.click(); }
            }
            if (event.key !== 'Tab') return;
            var items = Array.from(modal.querySelectorAll('a[href],button,input,select,[tabindex="0"]')).filter(function (el) { return !el.disabled && el.getClientRects().length; });
            if (!items.length) { event.preventDefault(); modal.focus(); return; }
            if (event.shiftKey && document.activeElement === items[0]) { event.preventDefault(); items[items.length - 1].focus(); }
            else if (!event.shiftKey && document.activeElement === items[items.length - 1]) { event.preventDefault(); items[0].focus(); }
        });
        sync();
    });
    var table = document.getElementById('mitigation-table');
    if (!table) return;
    var viewport = document.getElementById('view-mitigation');
    var slider = document.getElementById('timeline-xrange');
    var controls = document.getElementById('timeline-x-controls');
    var group = document.createElement('colgroup');
    var hasPhase = table.classList.contains('has-phase');
    var metaClasses = hasPhase ? ['c-phase','c-time','c-action','c-base','c-hit'] : ['c-time','c-action','c-base','c-hit'];
    var widths = hasPhase ? [56,56,148,74,96] : [56,148,74,96];
    var headers = Array.from(table.tHead.rows[0].cells);
    var players = headers.filter(function (cell) { return cell.hasAttribute('data-player'); });
    var skills = Array.from(table.tHead.rows[1].cells);
    // Single-skill groups still need room for their job abbreviation (e.g. MNK).
    // Wider groups retain the dense 32px skill columns.
    var skillWidths = [];
    headers.filter(function (cell) { return cell.classList.contains('mitigation-group'); }).forEach(function (cell) {
        for (var i = 0; i < cell.colSpan; i++) skillWidths.push(cell.colSpan === 1 ? 48 : 32);
    });
    widths.forEach(function (width) { var col = document.createElement('col'); col.style.width = width + 'px'; group.appendChild(col); });
    players.forEach(function (header) { var col = document.createElement('col'); col.style.width = '76px'; col.className = 'player-col'; col.dataset.player = header.dataset.player; group.appendChild(col); });
    skills.forEach(function (_, i) { var col = document.createElement('col'); col.style.width = skillWidths[i] + 'px'; group.appendChild(col); });
    table.insertBefore(group, table.firstChild);
    function updateBar() {
        var max = Math.max(0, viewport.scrollWidth - viewport.clientWidth);
        controls.hidden = viewport.classList.contains('hidden') || max < 2;
        slider.max = String(max); slider.value = String(viewport.scrollLeft);
        document.getElementById('timeline-scroll-left').disabled = viewport.scrollLeft < 1;
        document.getElementById('timeline-scroll-right').disabled = viewport.scrollLeft >= max - 1;
    }
    var pending = false;
    function layout() {
        if (pending) return;
        pending = true;
        requestAnimationFrame(function () {
            pending = false;
            var visible = players.filter(function (header) { return !document.body.classList.contains('hide-players') && !header.classList.contains('hidden') && header.style.display !== 'none'; });
            Array.from(group.children).slice(widths.length, widths.length + players.length).forEach(function (col, i) {
                col.style.display = visible.includes(players[i]) ? '' : 'none';
            });
            var total = widths.reduce(function (a,b) { return a+b; }, 0) + visible.length * 76 + skillWidths.reduce(function (a,b) { return a+b; }, 0);
            table.style.width = total + 'px'; table.style.minWidth = total + 'px';
            var left = 0;
            metaClasses.forEach(function (name, i) {
                headers[i].style.left = left + 'px'; headers[i].style.top = '0'; headers[i].style.zIndex = '6';
                table.querySelectorAll('tbody .' + name).forEach(function (cell) { cell.style.left = left + 'px'; });
                left += widths[i];
            });
            headers.slice(widths.length).forEach(function (cell) { cell.style.zIndex = '4'; cell.style.top = '0'; });
            var top = table.tHead.rows[0].getBoundingClientRect().height;
            skills.forEach(function (cell) { cell.style.top = top + 'px'; cell.style.zIndex = '4'; });
            updateBar();
        });
    }
    viewport.addEventListener('scroll', updateBar, { passive: true });
    slider.addEventListener('input', function () { viewport.scrollLeft = Number(slider.value); });
    document.getElementById('timeline-scroll-left').onclick = function () { viewport.scrollBy({ left:-240, behavior:'smooth' }); };
    document.getElementById('timeline-scroll-right').onclick = function () { viewport.scrollBy({ left:240, behavior:'smooth' }); };
    viewport.addEventListener('wheel', function (event) { if (event.shiftKey && Math.abs(event.deltaX) < 1) { event.preventDefault(); viewport.scrollLeft += event.deltaY; } }, { passive:false });
    new ResizeObserver(layout).observe(viewport);
    // Observe visibility changes, not the style updates made by layout itself.
    players.forEach(function (cell) { new MutationObserver(layout).observe(cell, { attributes:true, attributeFilter:['class'] }); });
    window.addEventListener('timeline:layout', layout);
    window.addEventListener('resize', layout);
    window.addEventListener('load', layout);
    layout();

})();

// 時間のかかる処理の「処理中」表示（上の処理は表のない画面では途中で抜けるので、別にしておく）
(function () {
    'use strict';
    // ボタンをスピナー付きにして押せなくし、説明と経過秒数を出す。
    // 共有URLの作成（timeline-share.js）からも window.LogsBusy.start で使う。
    var spinner = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>';
    window.LogsBusy = {
        start: function (button, label, box, message) {
            var original = button.innerHTML;
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.innerHTML = spinner + label;
            var started = Date.now(), timer = null;
            if (box) {
                box.innerHTML = '<span class="busy-text"></span><span class="busy-elapsed" aria-hidden="true"></span>';
                box.querySelector('.busy-text').textContent = message;
                box.hidden = false;
                var elapsed = box.querySelector('.busy-elapsed');
                timer = setInterval(function () {
                    elapsed.textContent = '（経過 ' + Math.floor((Date.now() - started) / 1000) + ' 秒）';
                }, 1000);
            }
            return function stop() {
                if (timer) clearInterval(timer);
                if (box) { box.hidden = true; box.innerHTML = ''; }
                button.disabled = false;
                button.removeAttribute('aria-busy');
                button.innerHTML = original;
            };
        }
    };
    // data-busy-label を持つフォーム（軽減の Analyze・DPS比較）は、送信から次の画面が出るまで処理中を表示する
    var stops = [];
    document.querySelectorAll('form[data-busy-label]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.sending === '1') { event.preventDefault(); return; } // 二重送信しない
            form.dataset.sending = '1';
            var button = form.querySelector('button[type="submit"]');
            if (!button) return;
            stops.push({ form: form, stop: window.LogsBusy.start(button, form.dataset.busyLabel, form.querySelector('.busy-status'),
                (form.dataset.busyMessage || '') + '（やり直すときは Esc キーを押すか、入力を直してください）') });
        });
    });
    function resetAll() {
        stops.splice(0).forEach(function (s) { s.stop(); delete s.form.dataset.sending; });
    }
    // 戻るボタンでキャッシュから表示されたときは、処理中のままにしない
    window.addEventListener('pageshow', function (event) { if (event.persisted) resetAll(); });
    // 読み込みの中断（Esc・停止ボタン）はイベントで分からない。Esc を押したとき・入力を直し始めたときは
    // やり直すものとみなして、もう一度送信できるようにする
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && stops.length) resetAll(); });
    document.addEventListener('input', function (event) {
        if (stops.length && event.target.closest && event.target.closest('form[data-busy-label]')) resetAll();
    });
})();
