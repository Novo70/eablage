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
        'protokoll_funktionen.php','app.css','desktop.css','preview_layout.js','search_results.js']) {
        fs.copyFileSync(path.join(web, name), path.join(dir, name));
    }
    let fixture = fs.readFileSync(path.join(__dirname, 'fixtures/ui_data.php'), 'utf8');
    fixture = fixture.replace("        return [\n            ['DokID'=>1", "        return array_slice(array_merge(...array_fill(0, 25, [\n            ['DokID'=>1")
        .replace('        ];\n    }\n}\nclass UiDatabase', "        ])), 0, min(50, (int)($_GET['fixture_count'] ?? 2)));\n    }\n}\nclass UiDatabase");
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

async function open({width=1440, height=900, desktop=false, count=150, query='Suchbegriff=Haus&Kategorie=1&page=2'} = {}) {
    const url = `dokumente.php?fixture_count=${count}&${query}` + (desktop ? '&layout=desktop' : '');
    const rendered = spawnSync(process.env.PHP_BIN || 'php', [path.join(dir,'run.php'), url], {cwd:dir,encoding:'utf8'});
    assert.equal(rendered.status,0,rendered.stderr);
    assert.equal(rendered.stderr,'');
    const context = await browser.newContext({viewport:{width,height},locale:'de-DE'});
    const page = await context.newPage();
    await page.route('http://eablage.test/**', async route => {
        const name = path.basename(new URL(route.request().url()).pathname);
        if (name === 'dokumente.php') return route.fulfill({contentType:'text/html',body:rendered.stdout});
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
