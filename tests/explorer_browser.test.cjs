// Requires Playwright/Chromium: node --test tests/explorer_browser.test.cjs
// Exercises the production HTML/JS with a mocked export plan; no NAS access.
const {test, before, after} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
const web = path.join(__dirname, '../web');
let browser;
before(async () => {
    browser = await chromium.launch({headless: true,
        ...(process.env.CHROMIUM_EXECUTABLE_PATH ? {executablePath: process.env.CHROMIUM_EXECUTABLE_PATH} : {}),
        args: ['--no-sandbox', '--disable-dev-shm-usage']});
});
after(async () => { if (browser) await browser.close(); });
const item = (id, dirs, name = 'Dokument.pdf', display = {}) => ({dok_id: id, directories: dirs, filename: name,
    display: {date: '2026-09-24', category: 'Finanzen', type: 'Beleg', partner: 'Müller', subject: name.slice(0, -4), number: '', ...display}});

async function open(items, query = '', viewport = {width: 1440, height: 1000}) {
    const page = await browser.newPage({viewport});
    const failures = [];
    page.on('pageerror', e => failures.push(e.message));
    // Use the actual page markup. Only PHP execution and network data are replaced.
    let markup = fs.readFileSync(path.join(web, 'explorer.php'), 'utf8');
    markup = markup.slice(markup.indexOf('<!DOCTYPE html>')).replace(/<\?[\s\S]*?\?>/g, '');
    const plan = {profile: 1, directory_structure: items.some(x => x.directories.length), items};
    markup = markup.replace('<script type="application/json" id="explorer-plan"></script>',
        '<script type="application/json" id="explorer-plan">' + JSON.stringify(plan).replace(/</g, '\\u003c') + '</script>');
    await page.route('http://explorer.test/**', async route => {
        const pathname = new URL(route.request().url()).pathname;
        if (pathname === '/app/explorer.php') return route.fulfill({contentType: 'text/html', body: markup});
        if (['explorer.js', 'explorer.css', 'app.css'].includes(path.basename(pathname))) {
            return route.fulfill({contentType: pathname.endsWith('.js') ? 'text/javascript' : 'text/css',
                body: fs.readFileSync(path.join(web, path.basename(pathname)), 'utf8')});
        }
        return route.fulfill({status: 404, contentType: 'text/plain', body: 'Test: Original-PDF fehlt.'});
    });
    await page.goto('http://explorer.test/app/explorer.php' + query);
    await page.locator('#explorer-layout').waitFor({state: 'visible'});
    return {page, failures};
}

test('drill down, preview original by ID, edit return, browser Back and clearing selection', async () => {
    const {page, failures} = await open([item(1, ['Haus', '2026', '09 - September']), item(2, [])]);
    try {
        await page.locator('#explorer-items button').filter({hasText: 'Haus'}).click();
        await page.locator('#explorer-items button').filter({hasText: '2026'}).click();
        await page.locator('#explorer-items button').filter({hasText: '09 - September'}).click();
        await page.locator('[data-dokid="1"]').click();
        assert.equal(await page.locator('#explorer-pdf iframe').getAttribute('src'), 'archiv_pdf.php?dokid=1');
        if (process.env.EXPLORER_SCREENSHOT_DIR) await page.screenshot({path: path.join(process.env.EXPLORER_SCREENSHOT_DIR, 'explorer-desktop.png'), fullPage: true});
        const link = new URL(await page.locator('#explorer-edit').getAttribute('href'), page.url());
        const back = new URL(link.searchParams.get('return'), page.url());
        assert.equal(back.searchParams.get('dokid'), '1');
        assert.deepEqual(JSON.parse(back.searchParams.get('folder')), ['Haus', '2026', '09 - September']);
        await page.goBack();
        assert.equal(await page.locator('#explorer-pdf iframe').count(), 0);
        await page.locator('#explorer-breadcrumb button').first().click();
        assert.equal(await page.locator('[data-dokid="2"]').count(), 1);
        assert.deepEqual(failures, []);
    } finally { await page.close(); }
});

test('selected document is restored at its new path, including on later list pages', async () => {
    const items = Array.from({length: 120}, (_, i) => item(i + 1, ['Neu'], `Datei ${i + 1}.pdf`));
    const {page} = await open(items, '?dokid=115&folder=' + encodeURIComponent('["Alt"]'));
    try {
        assert.match(await page.locator('#explorer-breadcrumb').textContent(), /Neu/);
        assert.equal(await page.locator('#explorer-page').textContent(), '2 / 2');
        assert.equal(await page.locator('[data-dokid="115"]').getAttribute('aria-current'), 'true');
        await page.locator('#explorer-prev').click();
        assert.equal(await page.locator('#explorer-page').textContent(), '1 / 2');
        assert.equal(await page.locator('[data-dokid="1"]').count(), 1);
    } finally { await page.close(); }
});

test('untrusted document metadata and folders are rendered only as text', async () => {
    const attack = '<img src=x onerror="window.attacked=true">';
    const {page, failures} = await open([item(1, [attack], attack + '.pdf')]);
    try {
        await page.locator('#explorer-items button').first().click();
        await page.locator('[data-dokid="1"]').click();
        assert.equal(await page.locator('#explorer img').count(), 0);
        assert.equal(await page.evaluate(() => window.attacked), undefined);
        assert.match(await page.locator('[data-dokid="1"]').textContent(), /<img/);
        assert.deepEqual(failures, []);
    } finally { await page.close(); }
});

test('empty and deleted selections have clear messages', async () => {
    const {page} = await open([], '?dokid=99');
    try {
        assert.equal(await page.locator('#explorer-notice').isVisible(), true);
        assert.match(await page.locator('#explorer-empty').textContent(), /keine Dokumente archiviert/);
        assert.equal(await page.locator('#explorer-actions').isVisible(), false);
    } finally { await page.close(); }
});

test('mobile layout avoids horizontal overflow and allows keyboard selection', async () => {
    const {page, failures} = await open([item(1, ['Müller & Söhne'], 'Langer Dateiname '.repeat(10) + '.pdf')], '', {width: 390, height: 844});
    try {
        await page.locator('#explorer-items button').first().focus();
        await page.keyboard.press('Enter');
        await page.locator('[data-dokid="1"]').focus();
        await page.keyboard.press('Enter');
        assert.equal(await page.locator('#explorer-actions').isVisible(), true);
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
        if (process.env.EXPLORER_SCREENSHOT_DIR) await page.screenshot({path: path.join(process.env.EXPLORER_SCREENSHOT_DIR, 'explorer-mobile.png'), fullPage: true});
        assert.deepEqual(failures, []);
    } finally { await page.close(); }
});

test('two equal desktop panels and compact header', async () => {
    const {page} = await open([item(1, [])]);
    try {
        assert.equal(await page.locator('#explorer-layout > section').count(), 2);
        for (const id of ['tree', 'summary', 'settings', 'reload']) {
            assert.equal(await page.locator('#explorer-' + id).count(), 0);
        }
        const panels = await page.locator('#explorer-layout > section').evaluateAll(nodes => nodes.map(n => n.getBoundingClientRect().width));
        assert.ok(Math.abs(panels[0] - panels[1]) < 2);
        assert.equal(await page.locator('#explorer-up').count(), 0);
        assert.equal(await page.locator('#explorer-filter-hint').count(), 0);
        assert.match(await page.locator('label[for="explorer-filter"]').textContent(), /inkl\. Unterordner/);
    } finally { await page.close(); }
});

test('scoped filter, descendant path, preview, reload, clear and breadcrumb navigation', async () => {
    const {page, failures} = await open([
        item(1, ['Kontoauszüge', '2025'], 'AB123 direkt.pdf'),
        item(2, ['Kontoauszüge', '2025', 'September'], 'ab123 September.pdf'),
        item(3, ['Kontoauszüge', '2024'], 'AB123 alt.pdf'),
        item(4, ['Kontoauszüge', '2025'], 'Anderes Konto.pdf')
    ], '?folder=' + encodeURIComponent('["Kontoauszüge","2025"]'));
    try {
        await page.locator('#explorer-filter').fill('AB123');
        assert.equal(await page.locator('#explorer-items button').count(), 2);
        assert.match(await page.locator('[data-dokid="2"]').textContent(), /Ordner: September/);
        await page.locator('[data-dokid="2"]').click();
        const edit = new URL(await page.locator('#explorer-edit').getAttribute('href'), page.url());
        const back = new URL(edit.searchParams.get('return'), page.url());
        assert.equal(back.searchParams.get('q'), 'AB123');
        assert.deepEqual(JSON.parse(back.searchParams.get('folder')), ['Kontoauszüge', '2025']);
        await page.reload();
        assert.equal(await page.locator('#explorer-filter').inputValue(), 'AB123');
        assert.equal(await page.locator('[data-dokid="2"]').getAttribute('aria-current'), 'true');
        await page.locator('#explorer-clear').click();
        assert.equal(await page.locator('#explorer-items button').count(), 3);
        assert.equal(await page.locator('#explorer-pdf iframe').count(), 0);
        await page.locator('#explorer-filter').fill('not found');
        assert.match(await page.locator('#explorer-empty').textContent(), /Keine passenden/);
        await page.locator('#explorer-breadcrumb button').filter({hasText: 'Kontoauszüge'}).click();
        assert.equal(await page.locator('#explorer-filter').inputValue(), '');
        assert.equal(await page.locator('#explorer-items button').count(), 2);
        assert.deepEqual(failures, []);
    } finally { await page.close(); }
});

test('folder count is inline and preview actions are in the heading without duplicate filename', async () => {
    const {page, failures} = await open([
        item(1, ['Arbeit und Beruf'], 'Erstes Dokument.pdf'),
        item(2, ['Arbeit und Beruf'], 'Zweites Dokument.pdf'),
        item(3, ['Einzeln'], 'Einzelnes Dokument.pdf')
    ]);
    try {
        const work = page.locator('#explorer-items button').filter({hasText: 'Arbeit und Beruf'});
        assert.match(await work.textContent(), /Arbeit und Beruf \(2 Dokumente\)/);
        assert.equal(await work.locator('small').count(), 0);
        assert.match(await page.locator('#explorer-items button').filter({hasText: 'Einzeln'}).textContent(), /\(1 Dokument\)/);
        await work.click();
        await page.locator('[data-dokid="1"]').click();
        assert.equal(await page.locator('#explorer-selection').isVisible(), false);
        assert.equal(await page.locator('.ea-explorer-preview > .panel-body').getByText('Erstes Dokument.pdf').count(), 0);
        const heading = page.locator('.ea-explorer-preview > .panel-heading');
        assert.equal(await heading.locator('#explorer-open').isVisible(), true);
        assert.equal(await heading.locator('#explorer-edit').isVisible(), true);
        assert.equal(await heading.locator('#explorer-open').getAttribute('href'), 'archiv_pdf.php?dokid=1');
        assert.deepEqual(failures, []);
    } finally { await page.close(); }
});

test('document cards show metadata and never the virtual export filename', async () => {
    const {page, failures} = await open([item(7, [], 'Nur-beim-Export.pdf', {
        date: '2026-09-20', category: 'Bank', type: 'Kontoauszug',
        partner: 'Sparkasse', subject: 'Monatsauszug', number: '4711'
    })], '', {width: 390, height: 844});
    try {
        const card = page.locator('[data-dokid="7"]');
        const content = await card.textContent();
        for (const value of ['2026-09-20', 'Bank', 'Kontoauszug', 'Sparkasse', 'Monatsauszug', '4711']) {
            assert.ok(content.includes(value), value);
        }
        assert.ok(!content.includes('Nur-beim-Export.pdf'));
        await page.locator('#explorer-filter').fill('4711');
        assert.equal(await page.locator('[data-dokid="7"]').count(), 1);
        await page.locator('#explorer-filter').fill('Nur-beim-Export');
        assert.equal(await page.locator('[data-dokid="7"]').count(), 0);
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
        assert.deepEqual(failures, []);
    } finally { await page.close(); }
});
