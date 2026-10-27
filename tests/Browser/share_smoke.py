"""End-to-end sharing using isolated fixture router.php, with no FFLogs credentials."""
import sys
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

base = sys.argv[1].rstrip('/')
with sync_playwright() as pw:
    browser = pw.chromium.launch(executable_path='/usr/bin/google-chrome', args=['--no-sandbox', '--no-proxy-server', '--disable-dev-shm-usage'])
    owner = browser.new_context(viewport={'width': 1440, 'height': 900})
    page = owner.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    page.on('console', lambda msg: errors.append(msg.text) if msg.type == 'error' else None)
    page.goto(base + '/__ui/timeline', wait_until='networkidle')
    page.get_by_role('button', name='URLで共有', exact=True).click()
    page.get_by_role('button', name='共有URLを作成する', exact=True).click()
    expect(page.locator('#timeline-share-url')).not_to_have_value('')
    url = page.locator('#timeline-share-url').input_value()
    assert '/ff14tools/logs2timeline/shared/' in url, url
    assert not any(cookie['name'] == 'fflogs_api' for cookie in owner.cookies())
    guest = browser.new_context(viewport={'width': 390, 'height': 900}, accept_downloads=True)
    reader = guest.new_page()
    reader.on('pageerror', lambda error: errors.append(str(error)))
    response = reader.goto(url, wait_until='networkidle')
    assert response.status == 200
    assert response.headers['x-robots-tag'] == 'noindex, nofollow, noarchive'
    expect(reader.get_by_text('共有結果（APIキー不要）', exact=True)).to_be_visible()
    assert reader.locator('button').filter(has_text='共有を停止する').count() == 0
    assert not any(cookie['name'] == 'fflogs_api' for cookie in guest.cookies())
    assert reader.evaluate('document.documentElement.scrollWidth <= innerWidth + 1')
    reader.locator('#timeline-xrange').evaluate('(e)=>{e.value=e.max;e.dispatchEvent(new Event("input"))}')
    assert reader.locator('#view-mitigation').evaluate('(e)=>e.scrollLeft>100')
    reader.locator('#timeline-xrange').evaluate('(e)=>{e.value=0;e.dispatchEvent(new Event("input"))}')
    reader.locator('#mitigation-table tbody .c-hit').first.click()
    expect(reader.locator('#damageDetailModal')).to_be_visible()
    reader.keyboard.press('Escape')
    reader.get_by_role('button', name='共有URL・管理', exact=True).click()
    expect(reader.locator('#timeline-share-url')).to_have_value(url)
    reader.get_by_role('button', name='コピー', exact=True).click()
    expect(reader.locator('#timeline-share-status')).to_contain_text('コピー')
    Path('/tmp/logs2timeline-applied').mkdir(exist_ok=True)
    reader.screenshot(path='/tmp/logs2timeline-applied/share-guest-390.png')
    assert not errors, errors
    page.goto(url, wait_until='networkidle')
    page.get_by_role('button', name='共有URL・管理', exact=True).click()
    page.on('dialog', lambda dialog: dialog.accept())
    page.get_by_role('button', name='共有を停止する', exact=True).click()
    expect(page.get_by_text('共有を停止しました', exact=True)).to_be_visible()
    response = reader.goto(url, wait_until='networkidle')
    assert response.status == 404
    expect(reader.get_by_text('共有結果を表示できません', exact=True)).to_be_visible()
    print('PASS: create, API-key-free guest, details, copy, owner deletion, guest 404; no JS errors')
    browser.close()
