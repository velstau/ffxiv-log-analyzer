"""Chrome UI regression against router.php fixtures; never calls real FFLogs APIs.
Usage: python3 tests/Browser/ui_smoke.py http://172.24.0.2:8101/ff14tools/partyscope
Requires Playwright Python and Chrome. Set UI_SCREENSHOTS for artifact output.
"""
import json
import re
import os
from pathlib import Path
import sys
from playwright.sync_api import sync_playwright, expect

BASE = sys.argv[1].rstrip('/')
OUT = Path(os.environ.get('UI_SCREENSHOTS', '/tmp/partyscope-applied'))
OUT.mkdir(parents=True, exist_ok=True)
with sync_playwright() as pw:
    browser = pw.chromium.launch(executable_path='/usr/bin/google-chrome', args=['--no-sandbox', '--disable-dev-shm-usage', '--no-proxy-server'])
    context = browser.new_context(viewport={'width': 1440, 'height': 900}, accept_downloads=True)
    page = context.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    page.on('console', lambda msg: errors.append(msg.text) if msg.type == 'error' else None)
    page.on('dialog', lambda dialog: (errors.append(dialog.message), dialog.dismiss()))

    def visit(path):
        response = page.goto(BASE + path, wait_until='networkidle')
        assert response.status == 200, (path, response.status)
        page.wait_for_timeout(150)

    for width in (1440, 390, 320):
        page.set_viewport_size({'width': width, 'height': 900})
        for label, path in [('home', '/'), ('mitigation', '/mitigation'), ('compare-form', '/compare'), ('party', '/party-search'), ('api-key', '/api-key'), ('timeline', '/__ui/timeline'), ('compare', '/__ui/compare')]:
            visit(path)
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), (label, width, 'body overflow')
            assert page.locator('html').get_attribute('data-bs-theme') == 'dark'
            assert page.locator('link[href*="tabler.min.css"]').count() == 1
            if label == 'timeline':
                assert page.locator('.player-job-icon').first.evaluate('(e)=>e.getBoundingClientRect().width') == 20
                assert page.locator('#view-mitigation').evaluate('(e)=>e.scrollWidth > e.clientWidth')
                # Uneven groups: narrow job/role labels and icons must stay inside their header.
                assert page.locator('.mitigation-group').evaluate_all('''(cells)=>cells.every(cell=>{
                    const box=cell.getBoundingClientRect();
                    return [...cell.querySelectorAll('img,.mitigation-group-label')].every(child=>{
                        const r=child.getBoundingClientRect();
                        return r.left>=box.left && r.right<=box.right && r.top>=box.top && r.bottom<=box.bottom;
                    });
                })''')
            page.screenshot(path=str(OUT / f'{label}-{width}.png'), full_page=True)
    page.set_viewport_size({'width': 1440, 'height': 900})
    # Hidden names: headers show job aliases and the badge, no real names remain.
    visit('/__ui/timeline?hide=1')
    expect(page.locator('.timeline-toolbar')).to_contain_text('プレイヤー名を非表示')
    expect(page.locator('#mitigation-table thead')).to_contain_text('SCH')
    assert 'Player 1' not in page.content()
    for phase in ('1', '0'):
        visit('/__ui/timeline?phase=' + phase)
        slider = page.locator('#timeline-xrange')
        slider.evaluate('(e)=>{e.value=e.max;e.dispatchEvent(new Event("input", {bubbles:true}))}')
        assert page.locator('#view-mitigation').evaluate('(e)=>e.scrollLeft > 100')
        page.locator('#timeline-scroll-left').click()
        page.locator('[onclick="togglePlayers()"] ').click()
        assert page.locator('body').evaluate('(e)=>e.classList.contains("hide-players")')
        page.locator('[onclick="togglePlayers()"] ').click()
        for tab in ('synergy', 'death', 'mitigation'):
            page.locator('#tab-btn-' + tab).click()
            expect(page.locator('#view-' + tab)).to_be_visible()
        # Restore initial horizontal position before interacting with sticky data.
        slider.evaluate('(e)=>{e.value=0;e.dispatchEvent(new Event("input", {bubbles:true}))}')
        page.locator('[onclick="openActionFilterModal()"] ').click()
        expect(page.locator('#actionFilterModal')).to_be_visible()
        page.locator('[onclick="toggleAllActions(false)"]').click()
        page.locator('[onclick="applyActionFilter()"]').click()
        assert page.locator('#mitigation-table tbody tr:not(.hidden)').count() == 0
        page.locator('[onclick="openActionFilterModal()"]').click()
        page.locator('[onclick="toggleAllActions(true)"]').click()
        page.locator('[onclick="applyActionFilter()"]').click()
        expect(page.locator('#actionFilterModal')).to_be_hidden()
        page.locator('#mitigation-table tbody .c-action').first.focus()
        page.keyboard.press('Enter')
        expect(page.locator('#actionDetailModal')).to_be_visible()
        page.keyboard.press('Escape')
        page.locator('#mitigation-table tbody .c-hit').first.click()
        expect(page.locator('#damageDetailModal')).to_be_visible()
        assert page.locator('#damageDetailContent').inner_text().strip()
        page.keyboard.press('Escape')
        # Original player detail image output uses the same shared capture routine.
        page.evaluate('openPlayerTimeline(Object.keys(window.playerDetails)[0])')
        expect(page.locator('#playerTimelineModal')).to_be_visible()
        with page.expect_download(timeout=60000) as download:
            page.locator('[onclick^="saveModalAsImage"]').click()
        download.value.save_as(OUT / f'player-phase{phase}.png')
        page.keyboard.press('Escape')
        expect(page.locator('.timeline-state-key > span .mit')).to_have_count(6)
    assert page.locator('[onclick="saveFullTimeline()"]').count() == 0

    visit('/party-search')
    for _ in range(9):
        page.locator('[data-job="Paladin"]').click()
    assert page.locator('.job-input').evaluate_all('(es)=>es.filter(e=>e.value).length') == 8
    assert page.locator('#slots img').first.evaluate('(e)=>e.complete && e.naturalWidth > 0')
    page.locator('#slots button').first.click()
    assert page.locator('.job-input').evaluate_all('(es)=>es.filter(e=>e.value).length') == 7
    page.locator('#clearBtn').click()
    assert page.locator('.job-input').evaluate_all('(es)=>es.every(e=>!e.value)')
    page.select_option('#zoneSelect', '77')
    assert page.locator('#encounterSelect option').count() == 1

    # Simulate the range API response only. The original URL and selection code run unchanged.
    requests = []
    def range_response(route):
        requests.append(route.request.url)
        route.fulfill(json={'a': {'partyRdps': 120000, 'combatSec': 20}, 'b': {'partyRdps': 119000, 'combatSec': 20}, 'html': '<p>Fixture range result</p>'})
    page.route('**/compare/range?**', range_response)
    visit('/__ui/compare')
    cv = page.locator('canvas.phase-ts').first
    cv.scroll_into_view_if_needed()
    geometry = cv.evaluate('(e)=>{const c=Chart.getChart(e),r=e.getBoundingClientRect();return {x:r.x+c.scales.x.getPixelForValue(10),x2:r.x+c.scales.x.getPixelForValue(35),y:r.y+c.chartArea.top+15}}')
    page.mouse.move(geometry['x'], geometry['y'])
    page.mouse.down()
    page.mouse.move(geometry['x2'], geometry['y'], steps=15)
    card = page.locator('.phase-card').first
    original_head = card.locator('.phase-party').inner_text()
    page.mouse.up()
    # The range result replaces the phase card (party totals and blocks) and the card goes back on clear.
    expect(card.locator('.phase-blocks')).to_contain_text('Fixture range result')
    expect(card.locator('.phase-party')).to_contain_text('A 120,000')
    expect(card.locator('.ph-range')).to_contain_text('選択範囲 0:10')
    expect(card).to_have_class(re.compile('range-active'))
    page.screenshot(path=str(OUT / 'compare-range-1440.png'), full_page=False)
    page.locator('.ts-panel').first.get_by_role('button', name='解除').click()
    expect(card.locator('.phase-party')).to_have_text(original_head)
    expect(card.locator('.phase-blocks')).not_to_contain_text('Fixture range result')
    expect(card.locator('.ph-range')).to_be_empty()
    assert requests and '/ff14tools/partyscope/compare/range?' in requests[0]
    assert 'anon=1' not in requests[0]
    expect(page.get_by_role('button', name='URLで共有')).to_be_visible()

    # Hidden names: the page and the range request both use aliases.
    visit('/__ui/compare?hide=1')
    assert 'Fixture Player with a long name' not in page.content()
    expect(page.locator('body')).to_contain_text('Paladin(A)')
    cv = page.locator('canvas.phase-ts').first
    cv.scroll_into_view_if_needed()
    geometry = cv.evaluate('(e)=>{const c=Chart.getChart(e),r=e.getBoundingClientRect();return {x:r.x+c.scales.x.getPixelForValue(10),x2:r.x+c.scales.x.getPixelForValue(35),y:r.y+c.chartArea.top+15}}')
    page.mouse.move(geometry['x'], geometry['y'])
    page.mouse.down()
    page.mouse.move(geometry['x2'], geometry['y'], steps=15)
    page.mouse.up()
    expect(page.locator('.phase-card .phase-blocks').first).to_contain_text('Fixture range result')
    assert 'anon=1' in requests[-1]
    assert not errors, errors
    print(json.dumps({'result': 'PASS', 'viewports': [1440, 390, 320], 'range_requests': len(requests), 'browser_errors': errors, 'artifacts': str(OUT)}, ensure_ascii=False))
    browser.close()
