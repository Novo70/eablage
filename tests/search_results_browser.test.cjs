// Actual search controller/template with synthetic read-only PDO data.
const {test, before, after} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const web = path.resolve(__dirname, '../web');
let dir, browser;

before(async () => {
    dir = fs.mkdtempSync(path.join(os.tmpdir(), 'eablage-search-layout-'));
    for (const name of ['dokumente.php','icons.php','navbar.php','vorgaenge_funktionen.php',
        'protokoll_funktionen.php','csrf.php','app.css','desktop.css','preview_layout.js','clickable_rows.js','search_results.js']) {
        fs.copyFileSync(path.join(web, name), path.join(dir, name));
    }
    let fixture = fs.readFileSync(path.join(__dirname, 'fixtures/ui_data.php'), 'utf8');
    fixture = fixture.replace("        return [\n            ['DokID'=>1", "        return array_slice(array_merge(...array_fill(0, 25, [\n            ['DokID'=>1")
        .replace('        ];\n    }\n}\nclass UiDatabase', "        ])), 0, min(50, (int)($_GET['fixture_count'] ?? 2)));\n    }\n}\nclass UiDatabase");
    fixture = fixture.replace(
        "'DokID'=>1,'Datum'=>'2026-10-26','Partner'=>'Müller & Söhne','Betreff'=>'Jahresabrechnung",
        "'DokID'=>(int)($_GET['page'] ?? 1)*10+1,'Datum'=>'2026-10-26','Partner'=>'Müller & Söhne','Betreff'=>'Jahresabrechnung"
    ).replace(
        "'DokID'=>2,'Datum'=>'2026-10-20','Partner'=>'Stadtwerke",
        "'DokID'=>(int)($_GET['page'] ?? 1)*10+2,'Datum'=>'2026-10-20','Partner'=>'Stadtwerke"
    );
    fs.writeFileSync(path.join(dir, 'konfiguration.php'), fixture);
    fs.writeFileSync(path.join(dir, 'funktionen.php'), '<?php');
    fs.writeFileSync(path.join(dir, 'run.php'), `<?php
$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']=$argv[1];
parse_str(parse_url($argv[1], PHP_URL_QUERY) ?? '', $_GET); $_REQUEST=$_GET; $_POST=[];
require __DIR__.'/dokumente.php';`);
    browser = await chromium.launch({headless:true, executablePath:process.env.PLAYWRIGHT_EXECUTABLE_PATH,
        args:['--no-sandbox','--disable-dev-shm-usage']});
});
after(async () => {
    if (browser) await browser.close();
    if (dir) fs.rmSync(dir, {recursive:true, force:true});
});

function renderSearch(url) {
    const rendered = spawnSync(process.env.PHP_BIN || 'php', ['-d','session.save_path='+dir,path.join(dir,'run.php'),url], {cwd:dir,encoding:'utf8'});
    assert.equal(rendered.status,0,rendered.stderr);
    assert.equal(rendered.stderr,'');
    return rendered.stdout;
}

async function open({width=1440, height=900, desktop=false, count=150, query='Suchbegriff=Haus&Kategorie=1&page=2'} = {}) {
    const url = `dokumente.php?fixture_count=${count}&${query}` + (desktop ? '&layout=desktop' : '');
    const context = await browser.newContext({viewport:{width,height},locale:'de-DE'});
    const page = await context.newPage();
    await page.route('http://eablage.test/**', async route => {
        const name = path.basename(new URL(route.request().url()).pathname);
        if (name === 'dokumente.php') {
            const url = new URL(route.request().url());
            const body = renderSearch(url.pathname + url.search);
            return route.fulfill({contentType:'text/html',body});
        }
        const file = path.join(dir,name);
        return fs.existsSync(file) ? route.fulfill({path:file}) : route.fulfill({status:404,body:''});
    });
    await page.goto('http://eablage.test/'+url);
    await page.evaluate(()=>document.fonts.ready);
    return {page,close:()=>context.close()};
}

for (const options of [{width:1440,height:900},{width:1920,height:900,desktop:true},{width:1280,height:650,desktop:true}]) {
    test(`${options.width}x${options.height}: fixed title/footer, bounded filter and scrolling results`, async () => {
        const {page,close} = await open(options);
        try {
            const panel = page.locator('.ea-documents-panel'), heading = page.locator('.ea-search-heading');
            const footer = page.locator('.ea-search-footer'), scroll = page.locator('.ea-results-scroll');
            assert.equal(await page.locator('main > section > :first-child').getAttribute('class'),'panel-heading ea-search-heading');
            assert.match(await heading.textContent(),/Suchergebnisse.*150 Treffer/s);
            assert.equal(await page.locator('#search-refine').evaluate(el=>el.open),false);
            assert.equal(await page.locator('.ea-results-table tbody tr').count(),50);
            assert.equal(await scroll.evaluate(el=>el.scrollHeight>el.clientHeight),true);
            const top = await heading.boundingBox(), bottom = await footer.boundingBox();
            assert.ok(bottom.y+bottom.height<=options.height,'footer fits in viewport');
            if (options.desktop) {
                const bounds = await panel.boundingBox();
                assert.ok(Math.abs(bounds.y-(options.height-bounds.y-bounds.height))<=1,'equal outer top/bottom insets');
            }
            await scroll.evaluate(el=>el.scrollTop=el.scrollHeight);
            const tableHeading = await page.locator('.ea-results-table th').first().boundingBox();
            const scrollBounds = await scroll.boundingBox();
            assert.ok(Math.abs(tableHeading.y-scrollBounds.y)<=1,'column headers reach the scroll-area edge without clipped rows above');
            assert.equal((await heading.boundingBox()).y,top.y);
            assert.equal((await footer.boundingBox()).y,bottom.y);
            assert.equal(await page.evaluate(()=>scrollY),0);
            await page.locator('#search-refine > summary').click();
            const filter = await page.locator('#search-refine').boundingBox();
            const area = await scroll.boundingBox();
            assert.ok(filter.height<=Math.min(options.height*.42,384)+1,'filter height is bounded');
            assert.ok(area.height>100,'results remain usable with expanded filter');
            assert.equal((await footer.boundingBox()).y,bottom.y);
            assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
            const fonts = await page.locator('.ea-results-table tbody tr').first().evaluate(row =>
                ['.ea-result-date', '.ea-partner', '.ea-number', '.ea-subject a'].map(selector => {
                    const style = getComputedStyle(row.querySelector(selector));
                    return [style.fontSize, style.fontWeight, style.lineHeight];
                })
            );
            assert.ok(fonts.every(font => JSON.stringify(font) === JSON.stringify(fonts[0])));
            if (process.env.SEARCH_SCREENSHOT_DIR) {
                fs.mkdirSync(process.env.SEARCH_SCREENSHOT_DIR,{recursive:true});
                await page.screenshot({path:path.join(process.env.SEARCH_SCREENSHOT_DIR,`${options.width}-${options.height}.png`),fullPage:true});
            }
        } finally { await close(); }
    });
}

for (const options of [{width:390,height:844},{width:800,height:900},{width:1440,height:500}]) {
    test(`${options.width}x${options.height}: mobile/short windows keep natural page scrolling`, async () => {
        const {page,close} = await open(options);
        try {
            assert.equal(await page.locator('.ea-results-scroll').evaluate(el=>getComputedStyle(el).overflowY),'visible');
            assert.ok(await page.evaluate(()=>document.documentElement.scrollHeight>innerHeight));
            assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
            await page.locator('#search-refine > summary').click();
            assert.equal(await page.locator('#Suchbegriff').isVisible(),true);
            const link = await page.locator('.ea-document-link').first().boundingBox();
            if (options.width<900) assert.ok(link.height>=44,'touch target retained');
        } finally { await close(); }
    });
}

test('single footer pagination and document links retain the search context', async () => {
    const {page,close} = await open();
    try {
        assert.equal(await page.locator('.pagination-wrapper').count(),1);
        assert.equal(await page.locator('.ea-search-footer .pagination-wrapper').count(),1);
        const next = new URL(await page.getByRole('link',{name:'Nächste »'}).getAttribute('href'),page.url());
        assert.equal(next.searchParams.get('page'),'3');
        assert.equal(next.searchParams.get('Suchbegriff'),'Haus');
        assert.equal(next.searchParams.get('Kategorie'),'1');
        const detail = new URL(await page.locator('.ea-document-link').first().getAttribute('href'),page.url());
        const back = new URL(detail.searchParams.get('return'),page.url());
        assert.equal(back.searchParams.get('page'),'2');
        assert.equal(back.searchParams.get('Suchbegriff'),'Haus');
    } finally { await close(); }
});

test('result rows open the document while checkboxes and text selection stay independent', async () => {
    const {page,close} = await open();
    try {
        await page.route('**/ansehen.php?*', route => route.fulfill({body:'Dokumentansicht'}));
        await page.emulateMedia({reducedMotion:'reduce'});
        const row = page.locator('.ea-results-table tbody tr').first();
        const partner = row.locator('.ea-partner');
        const number = row.locator('.ea-number');
        const link = row.locator('.ea-document-link');
        const detail = new URL(await link.getAttribute('href'), page.url()).href;
        const list = page.url();
        const background = () => row.evaluate(el => getComputedStyle(el).backgroundColor);
        const idleColor = await background();
        await partner.hover();
        assert.notEqual(await background(), idleColor);

        const checkbox = row.locator('[data-search-document]');
        await checkbox.check();
        assert.equal(page.url(), list);
        await page.locator('.ea-search-heading').hover();
        assert.notEqual(await background(), idleColor);
        await checkbox.uncheck();

        const textBounds = await number.evaluate(el => {
            const range = document.createRange();
            range.selectNodeContents(el);
            const {x,y,width,height} = range.getBoundingClientRect();
            return {x,y,width,height};
        });
        const y = textBounds.y + textBounds.height / 2;
        await page.mouse.move(textBounds.x + 1, y);
        await page.mouse.down();
        await page.mouse.move(textBounds.x + textBounds.width + 5, y, {steps:10});
        await page.mouse.up();
        assert.match(await page.evaluate(() => getSelection().toString()), /AB-123\/45/);
        assert.equal(page.url(), list);

        await partner.click();
        await page.waitForURL(detail);
        await page.goto(list);
        await link.focus();
        await page.keyboard.press('Enter');
        await page.waitForURL(detail);
    } finally { await close(); }
});

test('column sorting keeps filters and selected documents and restarts pagination', async () => {
    const {page,close} = await open();
    try {
        const headers = page.locator('.ea-results-table thead th');
        assert.deepEqual((await headers.allTextContents()).slice(1).map(label => label.trim()), [
            'Betreff', 'Partner', 'Datum ↓', 'Kategorie / Dokumenttyp', 'Nummer',
        ]);
        assert.equal(await headers.nth(4).getByRole('link').count(), 0);
        await page.locator('[data-search-document]').first().check();
        const subject = page.getByRole('columnheader', {name:'Betreff'});
        await subject.getByRole('link').click();
        assert.equal(await subject.getAttribute('aria-sort'), 'ascending');
        const url = new URL(page.url());
        assert.equal(url.searchParams.get('page'), '1');
        assert.equal(url.searchParams.get('Suchbegriff'), 'Haus');
        assert.equal(url.searchParams.get('Kategorie'), '1');
        assert.equal(await page.locator('#search-selection-count').innerText(), '1 Dokument ausgewählt');
        await subject.getByRole('link').click();
        assert.equal(await subject.getAttribute('aria-sort'), 'descending');
        const next = new URL(await page.getByRole('link', {name:'Nächste »'}).getAttribute('href'), page.url());
        assert.equal(next.searchParams.get('sort'), 'subject-desc');
        assert.equal(next.searchParams.get('page'), '2');
        const detail = new URL(await page.locator('.ea-document-link').first().getAttribute('href'), page.url());
        assert.equal(new URL(detail.searchParams.get('return'), page.url()).searchParams.get('sort'), 'subject-desc');
    } finally { await close(); }
});

test('mobile results put the subject first and allow sorting without table headings', async () => {
    const {page,close} = await open({width:390,height:844});
    try {
        const row = page.locator('.ea-results-table tbody tr').first();
        const bounds = await Promise.all(['.ea-subject', '.ea-partner', '.ea-result-date', '.ea-result-classification', '.ea-number']
            .map(selector => row.locator(selector).boundingBox()));
        const [subject, partner, date, badges, number] = bounds;
        assert.ok(subject.y < partner.y && Math.abs(partner.y - date.y) < 1 && date.y < badges.y && badges.y < number.y);
        await page.getByLabel('Sortieren nach').selectOption('number-desc');
        await page.getByRole('button', {name:'Sortieren',exact:true}).click();
        const url = new URL(page.url());
        assert.equal(url.searchParams.get('sort'), 'number-desc');
        assert.equal(url.searchParams.get('page'), '1');
        assert.equal(url.searchParams.get('Suchbegriff'), 'Haus');
        assert.equal(url.searchParams.get('Kategorie'), '1');
    } finally { await close(); }
});

test('footer submits the integrated form and reveals invalid collapsed criteria', async () => {
    const {page,close} = await open();
    try {
        const submit = page.getByRole('button',{name:'Suche aktualisieren'});
        assert.equal(await submit.evaluate(el=>el.form.id),'search-refine-form');
        await page.locator('#Suchbegriff').evaluate(el=>el.setCustomValidity('Bitte Suchbegriff prüfen'));
        await submit.click();
        assert.equal(await page.locator('#search-refine').evaluate(el=>el.open),true);
        assert.equal(await page.evaluate(()=>document.activeElement.id),'Suchbegriff');
        await page.locator('#Suchbegriff').evaluate(el=>el.setCustomValidity(''));
        await page.locator('#Suchbegriff').fill('Geänderte Suche');
        const request = page.waitForRequest(r=>r.url().includes('Suchbegriff=Ge%C3%A4nderte'));
        await submit.click();
        const url = new URL((await request).url());
        assert.equal(url.searchParams.get('page'),'1');
        assert.equal(url.searchParams.get('Kategorie'),'1');
    } finally { await close(); }
});

for (const count of [0,2]) test(`${count} hits: clear empty/single-page result and available actions`, async () => {
    const {page,close} = await open({count});
    try {
        assert.equal(await page.locator('.pagination-wrapper').count(),0);
        assert.equal(await page.getByRole('link',{name:'Neue Suche',exact:true}).isVisible(),true);
        if (!count) assert.match(await page.locator('.ea-results-scroll').textContent(),/Keine Dokumente gefunden/);
    } finally { await close(); }
});

test('select this page, keep selections across pages, cancel and save a case', async () => {
    const {page,close} = await open({query:'Suchbegriff=Haus&Kategorie=1&page=1'});
    try {
        let payload;
        await page.route('**/vorgang_anlegen.php', route => {
            payload = route.request().postData();
            return route.fulfill({json:{ok:true,id:42}});
        });
        await page.route('**/vorgaenge.php?*', route => route.fulfill({body:'Vorgang 42'}));
        const selectPage = page.getByRole('checkbox',{name:'Alle auf dieser Seite auswählen',exact:true});
        const status = page.locator('#search-selection-count');
        const create = page.getByRole('button',{name:'Vorgang anlegen',exact:true});
        const clear = page.getByRole('button',{name:'Auswahl aufheben',exact:true});
        assert.equal(await status.innerText(), 'Dokumente auswählen');
        assert.equal(await create.isVisible(), true);
        assert.equal(await create.isDisabled(), true);
        assert.equal(await clear.isDisabled(), true);
        const position = await create.boundingBox();
        await selectPage.check();
        assert.equal(await create.isEnabled(), true);
        assert.equal(await clear.isEnabled(), true);
        assert.deepEqual(await create.boundingBox(), position);
        assert.equal(await status.innerText(), '2 Dokumente ausgewählt');
        await page.getByRole('link',{name:'Nächste »'}).click();
        await page.waitForURL('**/dokumente.php?**page=2**');
        assert.equal(await status.innerText(), '2 Dokumente ausgewählt');
        assert.equal(await page.getByRole('checkbox',{name:'Dokument 21 auswählen',exact:true}).first().isChecked(), false);
        await page.getByRole('checkbox',{name:'Dokument 21 auswählen',exact:true}).first().check();
        assert.equal(await status.innerText(), '3 Dokumente ausgewählt');
        await create.click();
        const dialog = page.getByRole('dialog');
        await dialog.getByRole('button',{name:'Abbrechen',exact:true}).click();
        assert.equal(await status.innerText(), '3 Dokumente ausgewählt');
        assert.equal(payload, undefined);
        await create.click();
        await dialog.getByLabel('Bezeichnung',{exact:true}).fill('Versicherung');
        await dialog.getByRole('button',{name:'Vorgang speichern',exact:true}).click();
        await page.waitForURL('**/vorgaenge.php?*');
        assert.match(payload, /name="document_ids"\r?\n\r?\n\["11","12","21"\]/);
        const url = new URL(page.url());
        assert.equal(url.searchParams.get('id'), '42');
        const back = new URL(url.searchParams.get('return'), page.url());
        assert.equal(back.searchParams.get('page'), '2');
        assert.equal(back.searchParams.get('Suchbegriff'), 'Haus');
        await page.goto(back.href);
        assert.equal(await status.innerText(), 'Dokumente auswählen');
        assert.equal(await create.isDisabled(), true);
    } finally { await close(); }
});
