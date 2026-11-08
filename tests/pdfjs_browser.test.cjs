// Real PDF.js rendering, including its worker and local assets. No database or NAS needed.
const {test, before, after} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const {chromium} = require('playwright');
const {previewUrl} = require('../web/dokumentvorschau.js');
const {execFileSync}=require('node:child_process');
const web = path.resolve(__dirname, '../web');
let browser, server, base;
let oldAssets = false;
const integrationRequests = [];
function pdfFixture() {
    const objects = [null, '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [4 0 R 6 0 R 8 0 R] /Count 3 >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    for (let i = 1; i <= 3; i++) {
        const stream = `BT /F1 20 Tf 40 750 Td (eAblage PDF preview page ${i}) Tj ET`;
        objects.push(`<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents ${objects.length + 1} 0 R >>`);
        objects.push(`<< /Length ${Buffer.byteLength(stream)} >>\nstream\n${stream}\nendstream`);
    }
    let body = '%PDF-1.7\n';
    const offsets = [0];
    for (let i = 1; i < objects.length; i++) {
        offsets.push(Buffer.byteLength(body));
        body += `${i} 0 obj\n${objects[i]}\nendobj\n`;
    }
    const start = Buffer.byteLength(body);
    body += `xref\n0 ${objects.length}\n0000000000 65535 f \n`;
    for (const offset of offsets.slice(1)) body += `${String(offset).padStart(10, '0')} 00000 n \n`;
    body += `trailer\n<< /Size ${objects.length} /Root 1 0 R >>\nstartxref\n${start}\n%%EOF\n`;
    return Buffer.from(body);
}
before(async () => {
    server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://localhost');
        if (url.pathname === '/app/cache-seed') {
            res.setHeader('Content-Type','text/html; charset=utf-8');
            return res.end(`<link rel="stylesheet" href="pdfjs.css"><script src="pdfjs.js"></script><script src="vendor/pdfjs/build/pdf.mjs" type="module"></script><iframe src="${previewUrl('archiv_pdf.php?dokid=1')}"></iframe>`);
        }
        if (['/app/pdfjs.js','/app/pdfjs.css','/app/vendor/pdfjs/web/viewer.html','/app/vendor/pdfjs/build/pdf.mjs','/app/vendor/pdfjs/build/pdf.worker.mjs','/app/vendor/pdfjs/web/viewer.mjs'].includes(url.pathname)) {
            integrationRequests.push(url.pathname+url.search);
            if (oldAssets && !url.searchParams.has('v')) {
                res.setHeader('Cache-Control','public, max-age=31536000, immutable');
                res.setHeader('Content-Type',(/\.m?js$/.test(url.pathname))?'text/javascript':url.pathname.endsWith('.css')?'text/css':'text/html');
                return res.end((/\.m?js$/.test(url.pathname))?'window.eablageStaleAsset=true;':url.pathname.endsWith('.css')?'#eaSpreadToggle {display:none!important}':'<p>Cached viewer without spread toggle</p>');
            }
        }

        if (url.pathname === '/app/archiv_pdf.php' || url.pathname === '/app/pdf.php') {
            if (url.searchParams.get('dokid') === '404') { res.writeHead(404); return res.end('Missing PDF'); }
            const pdf = pdfFixture();
            res.writeHead(200, {'Content-Type': 'application/pdf', 'Content-Length': pdf.length});
            return res.end(pdf);
        }
        if (url.pathname === '/app/host-controls') {
            const component=execFileSync(process.env.PHP_BIN||'php',['-r',"require $argv[1]; eablage_dokumentvorschau('archiv_pdf.php?dokid=1', 'Stored text');",path.join(web,'dokumentvorschau.php')],{encoding:'utf8'});
            res.setHeader('Content-Type','text/html');
            return res.end(`<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="app.css"><div style="height:700px">${component}</div>`);
        }
        if (url.pathname === '/app/host') {
            res.setHeader('Content-Type', 'text/html');
            return res.end(`<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><iframe title="PDF" style="width:100%;height:700px;border:0" src="${previewUrl('archiv_pdf.php?dokid=1')}"></iframe>`);
        }
        const file = path.resolve(web, decodeURIComponent(url.pathname.replace(/^\/app\//, '')));
        if (!file.startsWith(web + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
            res.writeHead(404); return res.end();
        }
        const types = {'.html':'text/html', '.mjs':'text/javascript', '.js':'text/javascript', '.css':'text/css', '.json':'application/json', '.svg':'image/svg+xml', '.wasm':'application/wasm'};
        res.setHeader('Content-Type', types[path.extname(file)] || 'application/octet-stream');
        fs.createReadStream(file).pipe(res);
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    base = `http://127.0.0.1:${server.address().port}/app/`;
    browser = await chromium.launch({headless:true, executablePath:process.env.CHROMIUM_EXECUTABLE_PATH, args:['--no-sandbox', '--disable-dev-shm-usage']});
});
after(async () => { if (browser) await browser.close(); if (server) await new Promise(resolve => server.close(resolve)); });
async function rendered(page) {
    await page.goto(base + 'host');
    const frame = page.frames()[1];
    await frame.waitForFunction(() => window.PDFViewerApplication?.pdfViewer?.getPageView(0)?.renderingState === 3);
    return frame;
}
test('local viewer renders text, navigates, searches, resizes and preserves manual zoom', async () => {
    const page = await browser.newPage({viewport:{width:1300,height:900}});
    const errors = [], external = [], missing = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('request', r => { if (!r.url().startsWith(base) && !r.url().startsWith('blob:')) external.push(r.url()); });
    page.on('response', r => { if (r.status() >= 400) missing.push(r.url()); });
    try {
        const frame = await rendered(page);
        assert.equal(await frame.evaluate(() => PDFViewerApplication.pagesCount), 3);
        assert.ok(page.workers().some(worker => worker.url().includes('/pdf.worker.mjs')), 'the real PDF worker is running');
        if (process.env.PDFJS_SCREENSHOT) await page.screenshot({path:process.env.PDFJS_SCREENSHOT});
        await frame.locator('.textLayer').first().waitFor();
        assert.match(await frame.locator('.textLayer').first().textContent(), /eAblage PDF preview page 1/);
        assert.equal(await frame.evaluate(() => PDFViewerApplicationOptions.get('enableScripting')), false);
        assert.equal(await frame.evaluate(() => PDFViewerApplicationOptions.get('annotationEditorMode')), -1);
        assert.equal(await frame.locator('#editorModeButtons').isVisible(), false);
        await frame.locator('#pageNumber').fill('2');
        await frame.locator('#pageNumber').press('Enter');
        await frame.waitForFunction(() => PDFViewerApplication.page === 2);
        await frame.evaluate(() => PDFViewerApplication.eventBus.dispatch('find', {source:window, type:'', query:'preview', phraseSearch:true, caseSensitive:false, entireWord:false, highlightAll:true, findPrevious:false}));
        await frame.waitForFunction(() => PDFViewerApplication.findController.pageMatches.reduce((n, a) => n + a.length, 0) === 3);
        await frame.evaluate(() => { PDFViewerApplication.pdfViewer.currentScaleValue = 'page-width'; });
        const scale = await frame.evaluate(() => PDFViewerApplication.pdfViewer.currentScale);
        await page.locator('iframe').evaluate(el => { el.style.width = '650px'; });
        await frame.waitForFunction(previous => PDFViewerApplication.pdfViewer.currentScale < previous, scale);
        await frame.evaluate(() => { PDFViewerApplication.pdfViewer.currentScaleValue = '1.25'; });
        await page.locator('iframe').evaluate(el => { el.style.width = '950px'; });
        await frame.waitForFunction(() => innerWidth > 900);
        assert.equal(await frame.evaluate(() => PDFViewerApplication.pdfViewer.currentScale), 1.25);
        assert.deepEqual(external, []);
        assert.deepEqual(missing, []);
        assert.deepEqual(errors, []);
    } finally { await page.close(); }
});
test('missing PDF offers original link; unrelated and external sources are rejected', async () => {
    const page = await browser.newPage();
    try {
        await page.goto(base + previewUrl('archiv_pdf.php?dokid=404'));
        await page.locator('#ea-pdf-error').waitFor({state:'visible'});
        assert.equal(await page.locator('#ea-pdf-error a').getAttribute('href'), base + 'archiv_pdf.php?dokid=404');
        for (const file of ['https://example.com/private.pdf', '../../../konfiguration.php', '']) {
            await page.goto(base + 'vendor/pdfjs/web/viewer.html?file=' + encodeURIComponent(file));
            await page.locator('#ea-pdf-error').waitFor({state:'visible'});
            assert.equal(await page.locator('#ea-pdf-error a').count(), 0);
            assert.equal(await page.evaluate(() => new URLSearchParams(location.search).get('file')), '');
        }
    } finally { await page.close(); }
});
test('inbox names with spaces, ampersands and umlauts retain their exact file parameter', async () => {
    const page = await browser.newPage();
    const name = 'Müller & Söhne #1.pdf';
    try {
        const pdf = 'pdf.php?file=' + encodeURIComponent(name);
        await page.goto(base + previewUrl(pdf));
        await page.waitForFunction(() => window.PDFViewerApplication?.pdfDocument);
        const file = new URLSearchParams(new URL(page.url()).search).get('file');
        assert.equal(new URL(file).searchParams.get('file'), name);
    } finally { await page.close(); }
});

test('viewer also renders over plain HTTP as used by a local NAS', async () => {
    const page = await browser.newPage();
    try {
        await page.route('http://eablage.test/**', async route => {
            const url = new URL(route.request().url());
            const response = await route.fetch({url:base + url.pathname.replace(/^\/app\//, '') + url.search});
            await route.fulfill({response});
        });
        await page.goto('http://eablage.test/app/host');
        const frame = page.frames()[1];
        assert.equal(await frame.evaluate(() => isSecureContext), false);
        await frame.waitForFunction(() => window.PDFViewerApplication?.pdfViewer?.getPageView(0)?.renderingState === 3);
        assert.equal(await frame.evaluate(() => PDFViewerApplication.pagesCount), 3);
    } finally { await page.close(); }
});

test('touch viewport renders PDF.js with accessible navigation and no page overflow', async () => {
    const page=await browser.newPage({viewport:{width:390,height:844},isMobile:true,hasTouch:true,deviceScaleFactor:2});
    try {
        const frame=await rendered(page);
        await frame.locator('#pageNumber').fill('2');
        await frame.locator('#pageNumber').press('Enter');
        await frame.waitForFunction(()=>PDFViewerApplication.page===2);
        assert.ok(await frame.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
        assert.equal(await frame.locator('#secondaryToolbarToggleButton').isVisible(),true);
        assert.equal(await frame.locator('#ea-pdf-error').isVisible(),false);
    } finally { await page.close(); }
});

for (const width of [390,1300]) test(`${width}px: viewer toolbar spread toggle controls real PDF.js and survives document changes`,async()=>{
    const page=await browser.newPage({viewport:{width,height:900}});
    let release;
    const gate=new Promise(resolve=>release=resolve);
    let pdfRequests=0;
    page.on('request',r=>{if(r.url().includes('/archiv_pdf.php?'))pdfRequests++;});
    await page.route('**/archiv_pdf.php?dokid=1',async route=>{await gate;await route.continue();});
    try {
        await page.goto(base+'host-controls',{waitUntil:'domcontentloaded'});
        const toggle=page.frameLocator('iframe').locator('#eaSpreadToggle');
        assert.equal(await page.locator('[data-preview-spread]').count(),0);
        assert.equal(await page.getByRole('link',{name:'PDF öffnen',exact:true}).count(),1);
        await toggle.click(); // A choice before the PDF is ready must not be lost.
        assert.equal(await toggle.evaluate(el=>Boolean(el.closest('#toolbarViewerRight'))),true);
        assert.equal(await page.locator('iframe').getAttribute('data-preview-spread-mode'),'1');
        assert.equal(await toggle.getAttribute('aria-pressed'),'true');
        release();
        let frame=page.frames().find(f=>f.parentFrame()===page.mainFrame());
        await frame.waitForFunction(()=>window.PDFViewerApplication?.isInitialViewSet && PDFViewerApplication.pdfViewer.spreadMode===1);
        assert.deepEqual(await frame.locator('#viewer > .spread').first().locator(':scope > .page').evaluateAll(nodes=>nodes.map(n=>n.dataset.pageNumber)),['1','2']);
        assert.equal(pdfRequests,1);
        if(process.env.PDFSPREAD_SCREENSHOT_DIR) {
            await frame.waitForFunction(()=>PDFViewerApplication.pdfViewer.getPageView(0)?.renderingState===3 && PDFViewerApplication.pdfViewer.getPageView(1)?.renderingState===3);
            fs.mkdirSync(process.env.PDFSPREAD_SCREENSHOT_DIR,{recursive:true});
            await page.screenshot({path:path.join(process.env.PDFSPREAD_SCREENSHOT_DIR,`spread-${width}.png`),fullPage:true});
        }
        await frame.evaluate(()=>{PDFViewerApplication.pdfViewer.currentScaleValue='1.25';PDFViewerApplication.page=2;});
        await frame.waitForFunction(()=>PDFViewerApplication.page===2);
        assert.equal(await frame.evaluate(()=>PDFViewerApplication.page),2,'page selected before switching');
        await toggle.click();
        await frame.waitForFunction(()=>PDFViewerApplication.pdfViewer.spreadMode===0);
        assert.equal(await frame.evaluate(()=>PDFViewerApplication.page),2);
        assert.equal(await frame.evaluate(()=>PDFViewerApplication.pdfViewer.currentScale),1.25);
        assert.equal(pdfRequests,1);
        await frame.evaluate(()=>{PDFViewerApplication.pdfViewer.spreadMode=2;}); // Native viewer menu equivalent.
        await frame.waitForFunction(()=>document.getElementById('eaSpreadToggle').getAttribute('aria-pressed')==='true');
        await page.locator('#viewTextButton').click();
        assert.equal(await toggle.isVisible(),false);
        await page.locator('#viewPdfButton').click();
        assert.equal(await toggle.isVisible(),true);
        await page.evaluate(()=>eablageSelectDocumentPreview(document.querySelector('[data-document-preview]'),{pdfUrl:'archiv_pdf.php?dokid=2',text:'Second document'}));
        await page.waitForFunction(()=>document.querySelector('iframe').contentWindow.PDFViewerApplication?.isInitialViewSet===true);
        frame=page.frames().find(f=>f.parentFrame()===page.mainFrame());
        assert.equal(await frame.evaluate(()=>PDFViewerApplication.pdfViewer.spreadMode),2);
        assert.equal(await toggle.getAttribute('aria-pressed'),'true');
        await toggle.click();
        await frame.waitForFunction(()=>PDFViewerApplication.pdfViewer.spreadMode===0);
        assert.equal(pdfRequests,2);
        assert.equal(await toggle.getAttribute('aria-pressed'),'false');
    } finally {release();await page.close();}
});

test('cached old viewer files cannot hide the updated spread control or replace the matching library',async()=>{
    const page=await browser.newPage();
    try {
        oldAssets=true;
        await page.goto(base+'cache-seed');
        assert.equal(await page.evaluate(()=>window.eablageStaleAsset),true);
        oldAssets=false;
        integrationRequests.length=0;
        await page.goto(base+'host-controls');
        const version=await page.locator('[data-document-preview]').getAttribute('data-preview-version');
        assert.match(version,/^[a-f0-9]{16}$/);
        const frame=page.frames().find(f=>f.parentFrame()===page.mainFrame());
        await frame.locator('#eaSpreadToggle').waitFor({state:'visible'});
        assert.equal(await frame.evaluate(()=>window.eablageStaleAsset),undefined);
        await frame.waitForFunction(() => PDFViewerApplication?.pdfViewer?.getPageView(0)?.renderingState === 3);
        for(const file of ['pdfjs.js','pdfjs.css','vendor/pdfjs/web/viewer.html','vendor/pdfjs/web/viewer.mjs','vendor/pdfjs/build/pdf.mjs','vendor/pdfjs/build/pdf.worker.mjs']) {
            assert.ok(integrationRequests.some(r=>r.startsWith('/app/'+file+'?')&&new URL(r,base).searchParams.get('v')===version),file+' fetched with the deployment version');
        }
        await frame.locator('#eaSpreadToggle').click();
        await frame.waitForFunction(()=>PDFViewerApplication.pdfViewer.spreadMode===1);
        assert.equal(new URL(frame.url()).searchParams.get('v'),version,'iframe reload retains the asset version');
    } finally {oldAssets=false;await page.close();}
});


test('a failed viewer import immediately offers the original PDF', async () => {
    const page = await browser.newPage();
    try {
        await page.route('**/viewer.mjs?v=*', route => route.abort());
        await page.goto(base + 'host');
        const frame = page.frameLocator('iframe');
        const notice = frame.locator('#ea-pdf-error');
        await notice.waitFor({state:'visible', timeout:5000});
        assert.match(await notice.textContent(), /konnte nicht geladen/);
        assert.equal(await notice.getByRole('link', {name:'PDF öffnen'}).getAttribute('href'), base + 'archiv_pdf.php?dokid=1');
    } finally { await page.close(); }
});

test('a stalled first render offers the original and hides the notice when the PDF arrives', async () => {
    const page = await browser.newPage();
    let release;
    const gate = new Promise(resolve => release = resolve);
    try {
        await page.clock.install();
        await page.route('**/archiv_pdf.php?dokid=1', async route => { await gate; await route.continue(); });
        await page.goto(base + 'host', {waitUntil:'domcontentloaded'});
        const frame = page.frameLocator('iframe');
        const notice = frame.locator('#ea-pdf-error');
        await notice.waitFor({state:'attached'});
        assert.equal(await notice.isVisible(), false);
        await page.clock.fastForward(15001);
        await notice.waitFor({state:'visible'});
        assert.match(await notice.textContent(), /noch nicht angezeigt/);
        assert.equal(await notice.getByRole('link', {name:'PDF öffnen'}).getAttribute('href'), base + 'archiv_pdf.php?dokid=1');
        release();
        await notice.waitFor({state:'hidden'});
        assert.ok(await frame.locator('.page[data-loaded="true"] canvas').count());
    } finally { release(); await page.close(); }
});
