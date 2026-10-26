/* Publish a server-owned analysis snapshot; never upload API credentials or client-generated HTML. */
(function () {
    'use strict';
    var modal = document.getElementById('timeline-share');
    if (!modal) return;
    var input = document.getElementById('timeline-share-url');
    var status = document.getElementById('timeline-share-status');
    var error = document.getElementById('timeline-share-error');
    var create = document.getElementById('timeline-share-create');
    if (create) create.addEventListener('click', async function () {
        create.disabled = true;
        create.textContent = '共有URLを作成中…';
        error.hidden = true;
        try {
            var response = await fetch(modal.dataset.shareEndpoint, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': modal.querySelector('input[name="_token"]').value },
                body: JSON.stringify({ draft: modal.dataset.shareDraft })
            });
            var result = await response.json();
            if (!response.ok) throw new Error(response.status === 419 ? 'セッションが期限切れです。もう一度解析してください。' : (result.message || '共有URLを作成できませんでした。'));
            input.value = result.url;
            document.getElementById('timeline-share-open').href = result.url;
            document.getElementById('timeline-share-result').hidden = false;
            document.getElementById('timeline-share-expiry').textContent = '有効期限：' + new Date(result.expires_at).toLocaleString('ja-JP', { timeZone: 'Asia/Tokyo' }) + '（日本時間）';
            create.hidden = true;
            input.focus(); input.select();
        } catch (e) {
            error.textContent = e.message || '通信できませんでした。時間をおいて再度お試しください。';
            error.hidden = false;
        } finally {
            create.disabled = false;
            create.textContent = '共有URLを作成する';
        }
    });
    document.getElementById('timeline-share-copy').addEventListener('click', async function () {
        try {
            if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(input.value);
            else { input.focus(); input.select(); if (!document.execCommand('copy')) throw new Error('copy unavailable'); }
            status.textContent = '共有URLをコピーしました。';
        } catch (e) {
            input.focus(); input.select();
            status.textContent = '選択されたURLをコピーしてください。';
        }
    });
})();
