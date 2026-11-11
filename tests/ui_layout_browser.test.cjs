// Actual PHP templates with synthetic display data. No application services are used.
// UI_SCREENSHOT_DIR captures reproducible review images; UI_CAPTURE_ONLY=1 captures an older revision.
const {test, before, after} = require('node:test');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {spawnSync} = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const web = path.resolve(process.env.UI_SOURCE_DIR || path.join(__dirname, '../web'));
let browser, context, dir;
before(async () => {
    dir = fs.mkdtempSync(path.join(os.tmpdir(), 'eablage-ui-'));
    for (const name of fs.readdirSync(web)) if (fs.statSync(path.join(web, name)).isFile()) fs.copyFileSync(path.join(web, name), path.join(dir, name));
    fs.mkdirSync(path.join(dir,'vendor/pdfjs/web'),{recursive:true});
    fs.copyFileSync(path.join(web,'vendor/pdfjs/web/viewer.html'),path.join(dir,'vendor/pdfjs/web/viewer.html'));
    fs.copyFileSync(path.join(__dirname, 'fixtures/ui_data.php'), path.join(dir, 'konfiguration.php'));
    fs.writeFileSync(path.join(dir, 'funktionen.php'), "<?php require_once 'nummern_funktionen.php';");
    const source = fs.readFileSync(path.join(web, 'verarbeiten.php'), 'utf8');
    fs.writeFileSync(path.join(dir, 'classification-view.php'), source.slice(source.lastIndexOf('<!DOCTYPE html>')));
    const editSource=fs.readFileSync(path.join(web,'bearbeiten.php'),'utf8');
    fs.writeFileSync(path.join(dir,'editing-view.php'),editSource.slice(editSource.lastIndexOf('<!DOCTYPE html>')));
    fs.writeFileSync(path.join(dir, 'run.php'), `<?php
$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']=$argv[1];
parse_str(parse_url($argv[1], PHP_URL_QUERY) ?? '', $_GET); $_REQUEST=$_GET;
if (isset($argv[2])) {
    require 'csrf.php'; $_POST=json_decode($argv[2],true);
    $_POST['aktion_token']=eablage_aktion_token(); $_REQUEST=array_merge($_GET,$_POST); $_SERVER['REQUEST_METHOD']='POST';
}
$page=basename(parse_url($argv[1], PHP_URL_PATH));
if (in_array($page,['verarbeiten.php','bearbeiten.php'],true)) {
    require 'konfiguration.php'; require 'funktionen.php'; require 'meldungen.php';
    require 'dokumentvorschau.php'; require 'vorgaenge_funktionen.php';
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
    $aktionToken='test-token'; $datei='Versicherung.pdf'; $vollstaendigerPfad=__FILE__;
    $dokumentText=str_repeat("Beispieltext der Gebäudeversicherung.\\n", 100);
    $sourceSHA256=str_repeat('a',64); $erkennungsInfo='Manuell'; $job=null;
    $ungueltigeVorschlaege=$weitereKlassifikationsregeln=$autokatQuellen=$textvergleichQuellen=[];
    $jobResponse=['ok'=>true]; $pdfTextResult=['ok'=>true]; $klassifizierung=['numbers'=>[['kind_id'=>'1','value'=>'AB-123/45']]];
    $ollamaAvailable=$ollamaDiagnosticEnabled=$backendFehler=$erkennungsDetailsOffen=$pythonAutoKatErfolgreich=$pythonTextvergleichErfolgreich=false;
    $regelVorbereitungMoeglich=false; $pythonAutoKat=$pythonTextvergleich=[];
    if (isset($_GET['fixture_notice'])) $ungueltigeVorschlaege=['Kategorie'];
    if (isset($_GET['fixture_ki'])) { $ollamaAvailable=$ollamaDiagnosticEnabled=true; $ollamaBackgroundMode='aus'; }
    $erkannterPartner='Müller & Söhne'; $erkannterBetreff='Gebäudeversicherung'; $erkanntesDatum='2026-10-26';
    $erkannteKategorie=$erkannteKategorie2=$erkanntesDoktyp='1'; $erkannteNummer='AB-123/45'; $erkannteTags='Haus';
    $kategorien=$kategorien2=eablage_lade_kategorien($db); $dokumenttypen=eablage_lade_dokumenttypen($db);
    if ($page==='bearbeiten.php') {
        function changed_marker(...$args) { return ''; }
        $saved=isset($_GET['saved']); $textReloaded=$reclassified=false; $error=$reclassificationInfo=$pendingDocumentText='';
        $documentRevision='original-revision'; $reclassificationChanged=[]; $dokId=1;
        $returnUrl=$_GET['return'] ?? 'dokumente.php'; $pdfLink='archiv_pdf.php?dokid=1';
        $displayDocumentText=$storedDocumentText=$dokumentText; $filename='Versicherung.pdf';
        $document=$storedDocument=['DokID'=>1,'Datum'=>'2026-10-26','Partner'=>$erkannterPartner,'Betreff'=>$erkannterBetreff,'Tags'=>'Haus','doktyp_id'=>'1','kategorie_id'=>'1','kategorie2_id'=>'0','numbers'=>$klassifizierung['numbers'],'vorgaenge'=>[1]];
        require 'editing-view.php';
    } else { require 'classification-view.php'; }
} else { require $page; }
`);
    browser = await chromium.launch({headless:true, executablePath:process.env.PLAYWRIGHT_EXECUTABLE_PATH, args:['--no-sandbox']});
    context = await browser.newContext({locale:'de-DE'});
});
after(async () => { if (browser) await browser.close(); if (dir) fs.rmSync(dir, {recursive:true,force:true}); });
async function open(url, width=1440, post=null, touch=false) {
    const args=['-d','session.save_path='+dir, path.join(dir,'run.php'),url];
    if (post) args.push(JSON.stringify(post));
    const rendered=spawnSync(process.env.PHP_BIN || 'php', args, {cwd:dir,encoding:'utf8'});
    assert.equal(rendered.status,0,rendered.stderr);
    assert.equal(rendered.stderr,'');
    const page=touch ? await browser.newPage({locale:'de-DE',hasTouch:true}) : await context.newPage();
    await page.setViewportSize({width,height:900});
    await page.route('http://eablage.test/**', async route => {
        const name=path.basename(new URL(route.request().url()).pathname);
        if (name==='dokument_text.php') return route.fulfill({json:{text:'Gespeicherter Dokumenttext '+new URL(route.request().url()).searchParams.get('dokid')+' <script>literal</script>'}});
        if (name.endsWith('.php') && route.request().frame() === page.mainFrame()) return route.fulfill({contentType:'text/html',body:rendered.stdout});
        // Never recursively serve the application in the embedded PDF viewer.
        if (name.endsWith('.php')) return route.fulfill({contentType:'text/plain',body:'Test-PDF – Vorschau separat mit einem echten Dokument prüfen.'});
        const file=path.join(dir,name);
        if (fs.existsSync(file)) return route.fulfill({path:file});
        return route.fulfill({status:404,body:''});
    });
    await page.goto('http://eablage.test/'+url);
    await page.evaluate(()=>document.fonts.ready);
    return page;
}
const views=['protokoll.php','autokat_regel_bearbeiten.php','autokat_regeln.php','index.php','dokumente.php','stammdaten.php?kind=categories','stammdaten.php?kind=document_types','stammdaten.php?kind=number_types','einstellungen.php','explorer.php','ansehen.php?dokid=1','verarbeiten.php','bearbeiten.php','vorgaenge.php','vorgaenge.php?id=1'];
for (const width of [390,800,1440]) for (const url of views) test(`${width}px ${url}: layout and review capture`,async () => {
    const page=await open(url,width);
    try {
        if (url==='einstellungen.php') await page.locator('.ea-group-details').first().evaluate(el=>el.open=true);
        if (process.env.UI_SCREENSHOT_DIR) {
            fs.mkdirSync(process.env.UI_SCREENSHOT_DIR,{recursive:true});
            await page.screenshot({path:path.join(process.env.UI_SCREENSHOT_DIR,`${width}-${url.replace(/[^a-z0-9]/gi,'-')}.png`),fullPage:true});
        }
        if (process.env.UI_BASELINE_DIR) {
            const expected=path.join(process.env.UI_BASELINE_DIR,`${width}-${url.replace(/[^a-z0-9]/gi,'-')}.png`);
            assert.ok(fs.existsSync(expected), 'missing baseline: '+expected);
            assert.ok((await page.screenshot({fullPage:true})).equals(fs.readFileSync(expected)), 'visual change: '+expected);
        }
        if (process.env.UI_CAPTURE_ONLY) return;
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth), 'horizontal overflow');
        if (url==='dokumente.php' && width<900) {
            for (const link of await page.locator('.ea-results-table .ea-document-link').all()) {
                assert.ok((await link.boundingBox()).height>=44,'mobile document title has a 44px touch target');
            }
        }
        if (url==='einstellungen.php' && width>=900) {
            const row=page.locator('.ea-param-row').first();
            const label=await row.locator('.col-md-5').boundingBox(), field=await row.locator('.col-md-7').boundingBox();
            assert.ok(Math.abs(label.y-field.y)<40 && field.x>=label.x+label.width-1, 'settings columns sit side by side');
        }
        if (url.startsWith('stammdaten')) {
            assert.equal(await page.locator('#master-create').getAttribute('open'),null);
            assert.equal(await page.locator('#new-name').isVisible(),false);
            await page.locator('#master-create > summary').click();
            assert.equal(await page.locator('#new-name').isVisible(),true);
            await page.locator('#new-name').fill('Neuer Eintrag');
            assert.equal(await page.locator('#master-create form').evaluate(f=>f.checkValidity()),true);
        }
    } finally { await page.close(); }
});
test('form details scroll inside the left panel and KI diagnostics reveal themselves there', async () => {
    for (const width of [1440,390]) for (const url of ['verarbeiten.php?fixture_ki=1','bearbeiten.php']) {
        const page=await open(url,width);
        try {
            const details=page.locator('.ea-form-scroll > .ea-detail-panel');
            assert.equal(await details.count(),1);
            assert.equal(await details.getAttribute('open'),null);
            assert.equal(await page.locator('.ea-recognition-container, .container > .ea-detail-panel').count(),0);
            const footer=page.locator('.ea-form-footer');
            const before=await footer.boundingBox();
            await details.locator('summary').first().click();
            assert.equal(await details.evaluate(el=>el.open),true);
            if(width>=900) assert.equal((await footer.boundingBox()).y,before.y,'expanding details keeps actions fixed');
            if(url.startsWith('verarbeiten')) {
                await page.locator('#classification-more-actions summary').click();
                await page.locator('#ollama-diagnostic-open').click();
                assert.equal(await page.locator('#ollama-diagnostic-panel').isVisible(),true);
                assert.equal(await page.evaluate(()=>document.activeElement.id),'ollama-diagnostic-close');
                if(width>=900) {
                    assert.equal(await page.evaluate(()=>scrollY),0,'diagnostics scrolls only inside the left panel');
                    const close=await page.locator('#ollama-diagnostic-close').boundingBox();
                    const area=await page.locator('.ea-form-scroll').boundingBox();
                    assert.ok(close.y>=area.y && close.y+close.height<=area.y+area.height);
                }
            } else {
                assert.match(await details.getByRole('link',{name:'Dokumenthistorie öffnen'}).getAttribute('href'),/^protokoll\.php\?dokid=1/);
            }
        } finally { await page.close(); }
    }
});
test('workspace notices can be dismissed without submitting or changing the document', async () => {
    for (const width of [1440,390]) for (const url of ['verarbeiten.php?fixture_notice=1','bearbeiten.php?saved=1']) {
        const page=await open(url,width);
        try {
            const row=page.locator('.ea-classification-row');
            const original=await row.boundingBox();
            await page.locator('#Partner').fill('Nicht gespeicherte Änderung');
            const before=await page.locator('form[data-classification-draft], form[data-document-draft]').evaluate(form=>Array.from(new FormData(form).entries()));
            let posts=0;page.on('request',request=>{if(request.method()==='POST')posts++;});
            const close=page.getByRole('button',{name:'Meldung schließen'});
            assert.equal(await close.count(),1);
            await close.click();
            assert.equal(await page.locator('.ea-workspace-notices > .alert').count(),0);
            assert.equal(posts,0);
            assert.deepEqual(await page.locator('form[data-classification-draft], form[data-document-draft]').evaluate(form=>Array.from(new FormData(form).entries())),before);
            const after=await row.boundingBox();
            assert.ok(after.y<original.y,'dismissal frees space above the workspace');
            if(width>=900) {
                assert.ok(after.height>original.height,'desktop workspaces reclaim the available height');
                const footer=await page.locator('.ea-form-footer').boundingBox();
                assert.ok(footer.y+footer.height<=900);
            } else {
                assert.equal(after.height,original.height,'mobile retains natural panel height');
            }
        } finally { await page.close(); }
    }
});
test('workspace notices use available height without hiding actions or overlapping details', async () => {
    for (const url of ['verarbeiten.php','bearbeiten.php']) {
        const page=await open(url);
        try {
            const notices=page.locator('.ea-workspace-notices');
            const row=page.locator('.ea-classification-row');
            const footer=page.locator('.ea-form-footer');
            const initial=(await row.boundingBox()).height;
            for (const text of ['Änderungen gespeichert.', 'Die automatische Erkennung ist nicht verfügbar. Bitte die Felder manuell prüfen. '.repeat(3)]) {
                await notices.evaluate((el,text)=>{
                    const message=document.createElement('div');message.className='alert alert-info';message.textContent=text;el.append(message);
                },text);
                const box=await footer.boundingBox();
                assert.ok(box.y+box.height<=900,'actions stay inside viewport with notices');
                assert.ok((await row.boundingBox()).height<initial,'notices reduce workspace height');
                assert.ok(Math.abs((await page.locator('.ea-classification-panel').boundingBox()).height-(await page.locator('.ea-pdf-panel').boundingBox()).height)<1);
            }
            await notices.evaluate(el=>{el.firstElementChild.remove();});
            assert.ok((await row.boundingBox()).height<initial);
            await notices.evaluate(el=>{el.lastElementChild.style.height='850px';});
            const shell=await page.locator('.ea-workspace-shell').boundingBox();
            const workspace=await row.boundingBox();
            assert.ok(workspace.height>=360,'long notices retain usable workspace height');
            assert.ok(shell.y+shell.height>=workspace.y+workspace.height-1,'shell grows around long notices');
            assert.equal(await page.locator('.ea-form-scroll > .ea-detail-panel').count(),1,'details belong to the scrolling form');
        } finally { await page.close(); }
    }
});
test('classification and editing keep desktop actions visible while forms scroll', async () => {
    for (const url of ['verarbeiten.php','bearbeiten.php']) for (const width of [1440,900,390]) {
        const page=await open(url,width);
        try {
            const scroll=page.locator('.ea-form-scroll');
            const footer=page.locator('.ea-form-footer');
            const form=scroll.locator('..');
            const save=footer.getByRole('button',{name:'Speichern',exact:true});
            assert.equal(await save.evaluate(el=>el.form.id),await form.getAttribute('id'),'save remains associated with its original form');
            await scroll.evaluate(el=>{
                const extra=document.createElement('div'); extra.style.height='1200px'; extra.textContent='Zusätzliche Vorschläge und Nummern'; el.append(extra);
            });
            if (width>=900) {
                const left=await page.locator('.ea-classification-panel').boundingBox();
                const right=await page.locator('.ea-pdf-panel').boundingBox();
                assert.ok(Math.abs(left.height-right.height)<1,'form and preview heights match');
                const before=await footer.boundingBox();
                assert.ok(before.y+before.height<=900,'actions stay inside viewport');
                await scroll.evaluate(el=>el.scrollTop=el.scrollHeight);
                assert.ok(await scroll.evaluate(el=>el.scrollTop>0));
                assert.equal((await footer.boundingBox()).y,before.y);
                await page.locator('#Datum').evaluate(el=>el.value='');
                await save.click();
                assert.equal(await page.evaluate(()=>document.activeElement.id),'Datum','validation focuses invalid field');
                const field=await page.locator('#Datum').boundingBox(), area=await scroll.boundingBox();
                assert.ok(field.y>=area.y && field.y+field.height<=area.y+area.height,'validation reveals the invalid field');
                await page.locator('.ea-more-actions > summary').click();
                const menu=await page.locator('.ea-more-actions-list').boundingBox();
                assert.ok(menu.y>=left.y && menu.y+menu.height<=left.y+left.height,'menu fits outside the scrolling form');
            } else {
                assert.equal(await scroll.evaluate(el=>getComputedStyle(el).overflowY),'visible');
                assert.ok((await footer.boundingBox()).y>900,'mobile actions remain in normal page flow');
            }
        } finally { await page.close(); }
    }
});
test('desktop document metadata scrolls between its fixed title and actions; mobile keeps page scrolling', async () => {
    for (const width of [1440, 900, 390]) {
        const page=await open('ansehen.php?dokid=1',width);
        try {
            await page.locator('.ea-detail-panel').evaluate(el=>el.open=true);
            await page.locator('.ea-document-summary h2').evaluate(el=>el.textContent='Langer Dokumentbetreff '.repeat(30));
            const metadata=page.locator('.ea-document-scroll');
            const actions=page.locator('.ea-document-view .ea-document-actions');
            const panel=await page.locator('.ea-document-layout > .panel').boundingBox();
            const preview=await page.locator('.ea-document-preview').boundingBox();
            const before=await actions.boundingBox();
            if (width>=900) {
                assert.ok(Math.abs(panel.height-preview.height)<1,'both panels have equal height');
                assert.ok(before.y+before.height<=900,'actions remain inside the viewport');
                assert.ok(await metadata.evaluate(el=>el.scrollHeight>el.clientHeight),'long metadata overflows internally');
                await metadata.evaluate(el=>el.scrollTop=el.scrollHeight);
                assert.ok(await metadata.evaluate(el=>el.scrollTop>0),'metadata can scroll');
                assert.equal((await actions.boundingBox()).y,before.y,'actions stay fixed while metadata scrolls');
                assert.ok(await page.evaluate(()=>document.documentElement.scrollHeight<=innerHeight),'no outer page scrolling needed');
            } else {
                assert.equal(await metadata.evaluate(el=>getComputedStyle(el).overflowY),'visible');
                assert.ok(panel.height>900,'mobile metadata uses natural height');
                assert.ok(preview.y>=panel.y+panel.height,'mobile preview follows metadata');
                assert.ok(before.y>900,'mobile actions remain in page flow');
            }
        } finally { await page.close(); }
    }
    const shortPage=await open('ansehen.php?dokid=1');
    try {
        await shortPage.setViewportSize({width:1440,height:500});
        assert.equal(await shortPage.locator('.ea-document-scroll').evaluate(el=>getComputedStyle(el).overflowY),'visible');
    } finally { await shortPage.close(); }
});
test('document details are read-only, retain search context and share the PDF/text switch', async () => {
    const search='dokumente.php?Suchbegriff=Haus%20%26%20Hof&Kategorie=1&page=2';
    const page=await open('ansehen.php?dokid=1&return='+encodeURIComponent(search));
    try {
        assert.equal(await page.locator('main input, main select, main textarea, main form').count(),0);
        assert.equal(await page.getByRole('link',{name:'Zurück zur Suche'}).getAttribute('href'),search);
        const edit=new URL(await page.getByRole('link',{name:'Metadaten bearbeiten',exact:true}).getAttribute('href'),'http://eablage.test/');
        const view=new URL(edit.searchParams.get('return'),'http://eablage.test/');
        assert.equal(view.pathname,'/ansehen.php');
        assert.equal(view.searchParams.get('return'),search);
        assert.equal(await page.locator('.ea-document-numbers dd').count(),2);
        assert.match(await page.locator('.ea-document-summary h2').textContent(),/<script>alert\(1\)<\/script>/);
        assert.equal(await page.locator('.ea-document-summary script').count(),0);
        assert.equal(await page.locator('.ea-detail-panel').getAttribute('open'),null);
        await page.locator('#viewTextButton').click();
        assert.equal(await page.locator('#textDocumentView').isVisible(),true);
        await page.locator('#viewPdfButton').click();
        assert.equal(await page.locator('#pdfDocumentView').isVisible(),true);
        assert.equal(await page.getByRole('link',{name:'PDF öffnen',exact:true}).getAttribute('target'),'_blank');
    } finally { await page.close(); }
    const results=await open('dokumente.php?Suchbegriff=Haus&Kategorie=1&page=2&fixture_count=55');
    try {
        assert.equal(await results.locator('.ea-results-table .ea-edit-link').count(),0);
        const link=results.locator('.ea-document-link').first();
        assert.equal(await link.getAttribute('target'),null);
        const url=new URL(await link.getAttribute('href'),'http://eablage.test/');
        assert.equal(url.pathname,'/ansehen.php');
        assert.match(url.searchParams.get('return'),/page=2/);
        assert.match(url.searchParams.get('return'),/Kategorie=1/);
    } finally { await results.close(); }
});
test('navigation shows the current work area and closes with Escape, focus and outside clicks', async () => {
    const page=await open('dokumente.php?Suchbegriff=Haus');
    try {
        assert.equal(await page.locator('.ea-navbar__links [aria-current="page"]').innerText(),'Suche');
        await page.locator('#eaMenuBtn').click();
        assert.equal(await page.locator('#eaMenuBtn').getAttribute('aria-expanded'),'true');
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('#eaMenuBtn').getAttribute('aria-expanded'),'false');
        assert.equal(await page.locator('#eaMenuBtn').evaluate(el=>el===document.activeElement),true);
        await page.locator('#search-refine > summary').click();
        await page.locator('#eaMenuBtn').click();
        assert.match(await page.locator('#eaMenuDropdown a[href^="einstellungen.php"]').getAttribute('href'), /return=dokumente.php%3FSuchbegriff%3DHaus/);
        await page.locator('#Suchbegriff').focus();
        assert.equal(await page.locator('#eaMenuBtn').getAttribute('aria-expanded'),'false');
        await page.locator('#eaMenuBtn').click();
        await page.locator('.panel-heading').first().click();
        assert.equal(await page.locator('#eaMenuBtn').getAttribute('aria-expanded'),'false');
    } finally { await page.close(); }
});
test('classification keeps safe form actions and a working PDF/text switch', {timeout: 45000}, async () => {
    const page=await open('verarbeiten.php');
    try {
        assert.deepEqual(await page.locator('.ea-classification-actions .btn').allTextContents(),['Abbrechen','Speichern']);
        await page.locator('#classification-more-actions summary').click();
        const discard=page.locator('#classification-more-actions button[form="discard-inbox-file-form"]');
        let posts=0; page.on('request',r=>{if(r.method()==='POST')posts++;});
        await Promise.all([
            page.waitForEvent('dialog',{timeout:10000}).then(dialog=>dialog.dismiss()),
            discard.click({noWaitAfter:true})
        ]);
        assert.equal(posts,0);
        await page.locator('#viewTextButton').click();
        assert.equal(await page.locator('#textDocumentView').isVisible(),true);
        await page.locator('#viewPdfButton').click();
        assert.equal(await page.locator('#textDocumentView').isVisible(),false);
        await page.locator('#classification-more-actions summary').click();
        // A 204 fixture records the real submission without loading the same
        // classification form again while Playwright is handling its dialog.
        await page.route('**/eingang.php?action=discard',route=>route.fulfill({status:204}));
        const [submitted]=await Promise.all([
            page.waitForRequest(r=>r.method()==='POST',{timeout:10000}),
            page.waitForEvent('dialog',{timeout:10000}).then(dialog=>dialog.accept()),
            discard.click({noWaitAfter:true})
        ]);
        assert.match(submitted.url(),/eingang.php\?action=discard$/);
        const fields=new URLSearchParams(submitted.postData());
        assert.equal(fields.get('file'),'Versicherung.pdf');
        assert.equal(fields.get('aktion_token'),'test-token');
        assert.match(fields.get('sha256'),/^[a-f0-9]{64}$/);
    } finally { await page.close(); }
});
test('failed creation keeps the master-data form open and retains entered text',async () => {
    const page=await open('stammdaten.php',390,{kind:'categories',action:'create',name:'Haus & Garten',explanation:'Eigene Erläuterung'});
    try {
        assert.equal(await page.locator('#master-create').getAttribute('open'),'');
        assert.equal(await page.locator('#new-name').inputValue(),'Haus & Garten');
        assert.equal(await page.locator('#new-explanation').inputValue(),'Eigene Erläuterung');
        assert.match(await page.locator('.alert-danger').textContent(),/bereits vorhanden/);
    } finally { await page.close(); }
});
test('editing keeps field values and revision while switching PDF/text, and retains the Explorer return',async () => {
    const page=await open('bearbeiten.php?dokid=1&return=explorer.php');
    try {
        assert.equal(await page.locator('.ea-navbar__links [aria-current="page"]').innerText(),'Ablage');
        await page.locator('#Partner').fill('Noch nicht gespeichert');
        await page.locator('#viewTextButton').click();
        assert.equal(await page.locator('#textDocumentView').isVisible(),true);
        await page.locator('#viewPdfButton').click();
        assert.equal(await page.locator('#textDocumentView').isVisible(),false);
        assert.equal(await page.locator('#Partner').inputValue(),'Noch nicht gespeichert');
        assert.equal(await page.locator('[name=document_revision]').inputValue(),'original-revision');
        assert.equal(await page.locator('#edit-document-exit').getAttribute('href'),'explorer.php');
    } finally { await page.close(); }
});
test('pagination is hidden for one page, retained with filters for multiple pages, and empty results remain clear',async () => {
    for(const count of [0,2,51]) {
        const page=await open('dokumente.php?fixture_count='+count+'&Suchbegriff=Haus');
        try {
            assert.equal(await page.locator('.pagination-wrapper').count(),count>50 ? 1 : 0);
            if(count>50) {
                const next=new URL(await page.locator('.pagination-wrapper a').first().getAttribute('href'),page.url());
                assert.equal(next.searchParams.get('page'),'2');
                assert.equal(next.searchParams.get('Suchbegriff'),'Haus');
            }
            if(count===0) assert.match(await page.locator('.alert-warning').textContent(),/Keine Dokumente/);
        } finally { await page.close(); }
    }
});

for (const width of [390,800]) for (const url of ['ansehen.php?dokid=1','bearbeiten.php','verarbeiten.php','explorer.php','vorgaenge.php?id=1']) {
    test(`${width}px ${url}: mobile PDF preview is embedded with usable height`, async () => {
        const page=await open(url,width);
        try {
            if (url==='explorer.php') {
                await page.locator('#explorer-items button').first().click();
                await page.locator('[data-dokid="1"]').click();
            }
            if (url.startsWith('vorgaenge')) await page.locator('[data-case-preview]').first().click();
            const frame=page.locator('.ea-pdf-panel iframe, #explorer-pdf iframe, #vorgang-preview-pdf iframe');
            assert.equal(await frame.count(),1);
            assert.equal(await frame.isVisible(),true);
            const box=await frame.boundingBox();
            assert.ok(box.height>=360 && Math.abs(box.height-630)<2,'preview uses 70% of the 900px viewport');
            assert.ok(box.width<=width,'PDF preview fits the viewport');
            assert.equal(await page.getByText('PDF im Vollbild öffnen',{exact:true}).count(),0);
            assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no horizontal page overflow');
            if (url.startsWith('ansehen') || url==='bearbeiten.php' || url==='verarbeiten.php') {
                await page.locator('#viewTextButton').click();
                assert.equal(await frame.isVisible(),false);
                await page.locator('#viewPdfButton').click();
                assert.equal(await frame.isVisible(),true);
                assert.ok((await frame.boundingBox()).height>=360);
            }
            const original=await frame.elementHandle();
            await page.setViewportSize({width:1000,height:900});
            await page.setViewportSize({width,height:900});
            assert.equal(await original.evaluate(el=>el.isConnected),true,'resizing keeps the loaded iframe');
        } finally { await page.close(); }
    });
}

for (const [width,desktop] of [[390,false],[1440,false],[1920,true]]) {
    test(`${width}px: all five screens share preview controls and separate editing actions`, async()=>{
        const urls=['ansehen.php?dokid=1','bearbeiten.php','verarbeiten.php','explorer.php','vorgaenge.php?id=1'];
        let expected;
        for (const base of urls) {
            const url=base+(desktop?(base.includes('?')?'&':'?')+'layout=desktop':'');
            const page=await open(url,width);
            try {
                if(base==='explorer.php') { await page.locator('#explorer-items button').first().click();await page.locator('[data-dokid="1"]').click(); }
                if(base.startsWith('vorgaenge')) await page.locator('[data-case-preview]').first().click();
                const preview=page.locator('[data-document-preview]'), heading=preview.locator('> .panel-heading');
                assert.equal((await heading.locator('> span').textContent()).trim(),'Dokumentvorschau');
                const controls=await heading.locator('[data-preview-open], [data-preview-view]').allTextContents();
                expected??=controls;
                assert.deepEqual(controls,expected);
                assert.deepEqual(controls,['PDF öffnen','PDF','Text']);
                assert.equal(await heading.locator('[data-preview-spread]').count(),0);
                assert.equal(await page.getByRole('link',{name:'PDF öffnen',exact:true}).count(),1);
                assert.equal(await heading.getByRole('link',{name:'Metadaten bearbeiten',exact:true}).count(),0);
                assert.equal(await page.locator('[data-preview-split-toggle]').isVisible(),desktop);
                if(base.startsWith('ansehen')||base==='explorer.php'||base.startsWith('vorgaenge')) {
                    const edit=page.getByRole('link',{name:'Metadaten bearbeiten',exact:true});
                    assert.equal(await edit.evaluate(el=>Boolean(el.closest('.ea-workspace-footer, .ea-document-actions'))),true);
                }
                if(base.startsWith('vorgaenge')) assert.equal(await page.locator('#vorgang-detach').evaluate(el=>Boolean(el.closest('.ea-workspace-footer'))),true);
                const pdf=preview.locator('iframe'), frame=await pdf.elementHandle(), before=await pdf.boundingBox();
                await page.locator('#viewTextButton').click();
                if(base==='explorer.php'||base.startsWith('vorgaenge')) {
                    await page.waitForFunction(()=>document.querySelector('[data-preview-text-content]').textContent.startsWith('Gespeicherter Dokumenttext'));
                    assert.match(await preview.locator('pre').textContent(),/<script>literal<\/script>/);
                    assert.equal(await preview.locator('pre script').count(),0);
                }
                if(width<900) assert.ok(Math.abs((await page.locator('#textDocumentView').boundingBox()).height-before.height)<1,'mobile mode switch keeps preview height');
                await page.locator('#viewPdfButton').click();
                assert.equal(await frame.evaluate(el=>el.isConnected),true);
                assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
                if(process.env.UNIFIED_SCREENSHOT_DIR && base==='explorer.php') {
                    fs.mkdirSync(process.env.UNIFIED_SCREENSHOT_DIR,{recursive:true});
                    await page.screenshot({path:path.join(process.env.UNIFIED_SCREENSHOT_DIR,`preview-${width}.png`),fullPage:true});
                }
            } finally { await page.close(); }
        }
    });
}


// Long administration forms must scroll within the workspace in both layouts,
// including wide touch devices. Use actual templates and realistic repeated rows.
for (const [width, layout, touch] of [[900,'touch',false],[1440,'touch',false],[1920,'desktop',false],[1200,'touch',true]]) {
    for (const view of ['einstellungen.php','stammdaten.php?kind=categories','stammdaten.php?kind=document_types','stammdaten.php?kind=number_types']) {
        test(`${width}px ${layout}${touch?' touch device':''}: ${view} keeps administration title and actions visible`, async () => {
            const page=await open(view+(view.includes('?')?'&':'?')+'layout='+layout,width,null,touch);
            const settings=view==='einstellungen.php';
            const scroll=settings?'.ea-settings-scroll':'.ea-master-scroll';
            const heading=settings?'.ea-settings-panel > .panel-heading':'.ea-master-panel > .panel-heading';
            const footer=settings?'.ea-settings-footer':null;
            try {
                await page.evaluate(settings => {
                    const source=document.querySelector(settings?'.ea-param-row':'.ea-master-table tbody tr');
                    if(settings) document.querySelectorAll('.ea-group-details').forEach(group=>group.open=true);
                    for(let i=0;i<30;i++) source.parentElement.append(source.cloneNode(true));
                },settings);
                const assertPanelBottom=async()=>{
                    const container=await page.locator(settings?'.ea-settings-container':'.ea-master-container').boundingBox();
                    const panel=await page.locator(settings?'.ea-settings-panel':'.ea-master-panel').boundingBox();
                    assert.ok(Math.abs(panel.y+panel.height-container.y-container.height)<1,'panel reaches workspace bottom without extra margin');
                };
                await assertPanelBottom();
                const titleBefore=await page.locator(heading).boundingBox();
                const footerBefore=footer?await page.locator(footer).boundingBox():null;
                const tabsBefore=settings?null:await page.locator('.ea-master-panel .ea-tabs').boundingBox();
                if(footerBefore) assert.ok(footerBefore.y+footerBefore.height<=901,'footer stays in the viewport');
                assert.ok(await page.evaluate(()=>document.documentElement.scrollHeight<=innerHeight+1),'whole page must not overflow');
                assert.ok(await page.locator(scroll).evaluate(el=>el.scrollHeight>el.clientHeight+100),'long content needs inner scrolling');
                await page.locator(scroll).evaluate(el=>el.scrollTop=el.scrollHeight);
                assert.ok(await page.locator(scroll).evaluate(el=>el.scrollTop>0),'inner content scrolls');
                assert.deepEqual(await page.locator(heading).boundingBox(),titleBefore,'title stays fixed');
                if(footer) assert.deepEqual(await page.locator(footer).boundingBox(),footerBefore,'footer stays fixed');
                if(tabsBefore) assert.deepEqual(await page.locator('.ea-master-panel .ea-tabs').boundingBox(),tabsBefore,'area tabs stay fixed');
                if(settings) assert.equal(await page.locator('#settings-form button[type="submit"]').isVisible(),true);
                else {
                    assert.equal(await page.locator('.ea-master-panel footer').count(),0,'master data needs no footer');
                    assert.equal(await page.locator('#master-list-heading').count(),0,'active tab identifies the list');
                }
                await page.locator(scroll).evaluate(el=>el.scrollTop=0);
                await page.locator('.ea-admin-notices').evaluate(el=>{el.innerHTML='<div class="alert alert-success">Änderungen gespeichert.</div>';});
                assert.ok(await page.evaluate(()=>document.documentElement.scrollHeight<=innerHeight+1),'notice must fit above workspace');
                const withNotice=await page.locator(footer||'.ea-master-panel').boundingBox();
                assert.ok(withNotice.y+withNotice.height<=901,'notice preserves workspace height');
                await assertPanelBottom();
                if(process.env.UI_SCREENSHOT_DIR && width===1440) {
                    fs.mkdirSync(process.env.UI_SCREENSHOT_DIR,{recursive:true});
                    await page.screenshot({path:path.join(process.env.UI_SCREENSHOT_DIR,'admin-'+view.replace(/[^a-z0-9]/gi,'-')+'.png')});
                }
            } finally { await page.close(); }
        });
    }
}
for(const [width,height] of [[390,900],[800,900],[1440,550]]) {
    for(const view of ['einstellungen.php','stammdaten.php?kind=number_types']) test(`${width}x${height}: ${view} retains page scrolling on small viewports`,async()=>{
        const page=await open(view+(view.includes('?')?'&':'?')+'layout=touch',width);
        try {
            await page.setViewportSize({width,height});
            const settings=view==='einstellungen.php';
            await page.evaluate(settings=>{
                const source=document.querySelector(settings?'.ea-param-row':'.ea-master-table tbody tr');
                if(settings) document.querySelectorAll('.ea-group-details').forEach(group=>group.open=true);
                for(let i=0;i<12;i++) source.parentElement.append(source.cloneNode(true));
            },settings);
            assert.ok(await page.evaluate(()=>document.documentElement.scrollHeight>innerHeight+100),'small viewports retain document scrolling');
            assert.equal(await page.locator(settings?'.ea-settings-scroll':'.ea-master-scroll').evaluate(el=>getComputedStyle(el).overflowY),'visible');
            if(!settings) assert.equal(await page.locator('.ea-master-panel footer').count(),0,'master data has no footer');
        } finally { await page.close(); }
    });
}

for(const [width,layout,touch] of [[900,'touch',false],[1440,'touch',false],[1920,'desktop',false],[1200,'touch',true]]) {
    test(`${width}px ${layout}: rules have fixed title, footer and table headers`,async()=>{
        const url='autokat_regeln.php?fixture_count=75&seite=2&q=Haus&typ=K&aktiv=1&nutzung=mit&layout='+layout;
        const page=await open(url,width,null,touch);
        try {
            await page.locator('.ea-autokat-table tbody').evaluate(body=>{
                const source=body.querySelector('tr');for(let i=0;i<24;i++)body.append(source.cloneNode(true));
            });
            const heading=page.locator('.ea-autokat-panel > .panel-heading');
            const footer=page.locator('.ea-autokat-footer');
            const scroll=page.locator('.ea-autokat-scroll');
            const beforeHeading=await heading.boundingBox(),beforeFooter=await footer.boundingBox();
            const reference=await open('einstellungen.php?layout='+layout,width,null,touch);
            const referenceHeight=(await reference.locator('.ea-settings-panel > .panel-heading').boundingBox()).height;
            await reference.close();
            assert.ok(Math.abs(beforeHeading.height-referenceHeight)<1,'title height matches settings in the actual selected layout');
            assert.equal(await heading.getByRole('link',{name:'Neue Regel'}).count(),0);
            const create=footer.getByRole('link',{name:'Neue Regel'});
            assert.equal(await create.getAttribute('href'),'autokat_regel_bearbeiten.php');
            assert.equal(await create.isVisible(),true);
            const container=await page.locator('.ea-autokat-container').boundingBox();
            const panel=await page.locator('.ea-autokat-panel').boundingBox();
            assert.ok(Math.abs(panel.y+panel.height-container.y-container.height)<1,'no extra bottom gap');
            assert.ok(beforeFooter.y+beforeFooter.height<=901,'footer fits viewport');
            assert.ok(Math.abs(beforeFooter.y+beforeFooter.height-panel.y-panel.height+1)<2,'footer ends at panel edge');
            assert.ok(await page.evaluate(()=>document.documentElement.scrollHeight<=innerHeight+1),'page stays within viewport');
            assert.equal(await page.locator('.ea-autokat-scroll .search-summary').count(),0,'rule counts appear only in the footer');
            assert.equal(await page.locator('.ea-autokat-table th.ea-rule-rank').evaluate(el=>{
                const range=document.createRange();range.selectNodeContents(el);return range.getClientRects().length;
            }),1,'rank heading fits one line');
            if(width>=1200) {
                const input=await page.locator('#q').boundingBox();
                const filter=await page.locator('.ea-autokat-toolbar button[type="submit"]').boundingBox();
                assert.ok(Math.abs(input.y+input.height-filter.y-filter.height)<1,'filter aligns with the bottom of the input fields');
            }
            assert.equal(await page.locator('#q').inputValue(),'Haus');
            assert.equal(await page.locator('#typ').inputValue(),'K');
            assert.equal(await page.locator('.ea-autokat-pagination-top').isVisible(),false);
            for(const link of await footer.locator('.pagination-wrapper a').all()) {
                const params=new URL(await link.getAttribute('href'),'http://eablage.test').searchParams;
                assert.equal(params.get('q'),'Haus');assert.equal(params.get('typ'),'K');
                assert.equal(params.get('aktiv'),'1');assert.equal(params.get('nutzung'),'mit');
            }
            await scroll.evaluate(el=>el.scrollTop=500);
            const scrollBox=await scroll.boundingBox();
            const th=await page.locator('.ea-autokat-table th').first().boundingBox();
            assert.ok(Math.abs(th.y-scrollBox.y)<2,'table header sticks below title');
            assert.deepEqual(await heading.boundingBox(),beforeHeading);
            assert.deepEqual(await footer.boundingBox(),beforeFooter);
            await page.locator('textarea[name="test_text"]').fill('Beispieltext');
            assert.equal(await page.getByRole('button',{name:'Test ausführen'}).isVisible(),true);
            assert.deepEqual(await footer.boundingBox(),beforeFooter);
            await page.locator('.ea-admin-notices').evaluate(el=>el.innerHTML='<div class="alert alert-success">Regel aktiviert.</div>');
            assert.deepEqual(await footer.boundingBox(),beforeFooter,'notice consumes content height');
            if(process.env.UI_SCREENSHOT_DIR) {
                await scroll.evaluate(el=>el.scrollTop=0);
                await page.screenshot({path:path.join(process.env.UI_SCREENSHOT_DIR,`rules-${width}-${layout}.png`)});
            }
        } finally {await page.close();}
    });
}
for(const [width,height] of [[390,900],[800,900],[1440,550]]) test(`${width}x${height}: rules retain page scrolling and reachable rule test`,async()=>{
    const page=await open('autokat_regeln.php?layout=touch#test-ergebnis',width,{action:'test',test_text:'Beispieltext'});
    try {
        await page.setViewportSize({width,height});
        assert.equal(await page.locator('textarea[name="test_text"]').inputValue(),'Beispieltext');
        assert.match(await page.locator('.ea-test-result').innerText(),/Keine passende AutoKat-Regel/);
        assert.equal(await page.locator('.ea-autokat-scroll').evaluate(el=>getComputedStyle(el).overflowY),'visible');
        assert.ok(await page.evaluate(()=>document.documentElement.scrollHeight>innerHeight),'small window scrolls normally');
        await page.locator('textarea[name="test_text"]').scrollIntoViewIfNeeded();
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no horizontal overflow');
    } finally {await page.close();}
});

for(const layout of ['touch','desktop']) test(`${layout}: rule test results and inline status changes remain usable in the workspace`,async()=>{
    const page=await open('autokat_regeln.php?layout='+layout+'#test-ergebnis',layout==='desktop'?1920:1440,{action:'test',test_text:'Beispieltext'});
    try {
        assert.match(await page.locator('.ea-test-result').innerText(),/Keine passende AutoKat-Regel/);
        await page.locator('.ea-test-result').scrollIntoViewIfNeeded();
        assert.ok(await page.evaluate(()=>document.documentElement.scrollHeight<=innerHeight+1));
        const scroll=page.locator('.ea-autokat-scroll');
        await scroll.evaluate(el=>el.scrollTop=0);
        const toggle=page.locator('.ea-rule-status').first();
        const urlBefore=page.url();
        await page.route('**/autokat_regeln.php**',route=>route.fulfill({json:{ok:true,active:false}}));
        await toggle.click();
        await page.waitForFunction(()=>document.querySelector('.ea-rule-status').textContent.includes('inaktiv'));
        assert.equal(page.url(),urlBefore,'status changes without navigation');
        assert.equal(await toggle.getAttribute('aria-label'),'Regel aktivieren');
    } finally {await page.close();}
});

for(const [width,layout,touch] of [[900,'touch',false],[1440,'touch',false],[1920,'desktop',false],[1200,'touch',true]]) {
    for(const query of ['', '&regel=R0001']) test(`${width}px ${layout}: ${query?'existing':'new'} rule editor has fixed title and save actions`,async()=>{
        const page=await open('autokat_regel_bearbeiten.php?layout='+layout+query,width,null,touch);
        try {
            const heading=page.locator('.ea-rule-editor-heading'),footer=page.locator('.ea-rule-editor-footer');
            const scroll=page.locator('.ea-rule-editor-scroll');
            const beforeHeading=await heading.boundingBox(),beforeFooter=await footer.boundingBox();
            const reference=await open('einstellungen.php?layout='+layout,width,null,touch);
            const referenceHeight=(await reference.locator('.ea-settings-panel > .panel-heading').boundingBox()).height;
            await reference.close();
            assert.ok(Math.abs(beforeHeading.height-referenceHeight)<1,'editor uses the actual administration title height');
            assert.ok(await page.evaluate(()=>document.documentElement.scrollHeight<=innerHeight+1),'outer page fits');
            assert.ok(await scroll.evaluate(el=>el.scrollHeight>el.clientHeight),'form has an internal scroll area');
            await page.locator('#Bezeichnung').fill('Neue Regelbezeichnung');
            await page.locator('#Suchbegriffe').fill('Versicherung');
            await scroll.evaluate(el=>el.scrollTop=el.scrollHeight);
            assert.deepEqual(await heading.boundingBox(),beforeHeading);
            assert.deepEqual(await footer.boundingBox(),beforeFooter);
            assert.equal(await footer.getByRole('link',{name:'Abbrechen'}).getAttribute('href'),'autokat_regeln.php');
            assert.equal(await footer.getByRole('button',{name:'Speichern'}).isVisible(),true);
            const data=await page.locator('.ea-rule-editor-form').evaluate(form=>Object.fromEntries(new FormData(form)));
            assert.equal(data.Bezeichnung,'Neue Regelbezeichnung');assert.equal(data.Suchbegriffe,'Versicherung');
            assert.equal(data.is_new,query?'0':'1');assert.ok(data.aktion_token);
            await page.getByRole('button',{name:'Nummernerkennung hinzufügen'}).click();
            assert.equal(await page.locator('[data-rule-number-rows] fieldset').count(),1);
            await page.locator('.ea-admin-notices').evaluate(el=>el.innerHTML='<div class="alert alert-danger">Bitte Eingabe prüfen.</div>');
            assert.deepEqual(await footer.boundingBox(),beforeFooter,'notice preserves footer position');
            if(process.env.UI_SCREENSHOT_DIR) {
                await scroll.evaluate(el=>el.scrollTop=0);
                await page.screenshot({path:path.join(process.env.UI_SCREENSHOT_DIR,`rule-editor-${width}-${layout}-${query?'edit':'new'}.png`)});
            }
        } finally {await page.close();}
    });
}
for(const [width,height] of [[390,900],[800,900],[1440,550]]) test(`${width}x${height}: rule editor keeps page scrolling and invalid inputs`,async()=>{
    const page=await open('autokat_regel_bearbeiten.php?layout=touch',width,{is_new:'1',regel_id:'R0003',Bezeichnung:'Ungespeicherte Regel',Suchbegriffe:'',number_rules_json:'[]'});
    try {
        await page.setViewportSize({width,height});
        assert.equal(await page.locator('#Bezeichnung').inputValue(),'Ungespeicherte Regel');
        assert.match(await page.locator('.ea-admin-notices').innerText(),/Mindestens ein Suchbegriff/);
        assert.equal(await page.locator('.ea-rule-editor-scroll').evaluate(el=>getComputedStyle(el).overflowY),'visible');
        await page.locator('.ea-rule-editor-footer').scrollIntoViewIfNeeded();
        assert.ok(await page.evaluate(()=>document.documentElement.scrollHeight>innerHeight));
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    } finally {await page.close();}
});

for(const [width,layout,touch] of [[900,'touch',false],[1440,'touch',false],[1920,'desktop',false],[1200,'touch',true]]) test(`${width}px ${layout}: event history keeps title, paging and return visible`,async()=>{
    const back='bearbeiten.php?dokid=1';
    const page=await open('protokoll.php?fixture_count=150&seite=2&dokid=1&ereignis=metadaten_geaendert&regel=R0001&return='+encodeURIComponent(back)+'&layout='+layout,width,null,touch);
    try {
        const scroll=page.locator('.ea-history-scroll'),heading=page.locator('.ea-history-heading'),footer=page.locator('.ea-history-footer');
        await scroll.locator('.ea-group-details').first().locator('.ea-result-date').click();
        assert.match(await scroll.locator('.ea-group-details').first().innerText(),/Alt <script>/);
        assert.equal(await scroll.locator('.ea-group-body script').count(),0);
        await scroll.evaluate(el=>{const source=el.querySelector('details');for(let i=0;i<50;i++)el.append(source.cloneNode(true));});
        const reference=await open('einstellungen.php?layout='+layout,width,null,touch);
        const height=(await reference.locator('.ea-settings-panel > .panel-heading').boundingBox()).height;
        await reference.close();
        const titleBefore=await heading.boundingBox(),footerBefore=await footer.boundingBox();
        assert.ok(Math.abs(titleBefore.height-height)<1,'same title height as settings');
        assert.ok(await page.evaluate(()=>document.documentElement.scrollHeight<=innerHeight+1),'page fits viewport');
        const panel=await page.locator('.ea-history-panel').boundingBox();
        assert.ok(Math.abs(footerBefore.y+footerBefore.height-panel.y-panel.height+1)<2,'footer meets panel edge');
        assert.match(await footer.innerText(),/150 Ereignisse.*Seite 2 von 3/);
        assert.equal(await footer.getByRole('link',{name:'Zurück',exact:true}).getAttribute('href'),back);
        if(width>=1200) {
            const field=await page.locator('.ea-history-toolbar [name="dokid"]').boundingBox();
            const button=await page.locator('.ea-history-toolbar button[type="submit"]').boundingBox();
            assert.ok(Math.abs(field.y+field.height-button.y-button.height)<1,'filter controls align in both layouts');
        }
        const editLink = scroll.getByRole('link', {name:'Dokument bearbeiten'}).first();
        const editUrl = new URL(await editLink.getAttribute('href'), page.url());
        assert.equal(editUrl.pathname, '/bearbeiten.php');
        const historyUrl = new URL(editUrl.searchParams.get('return'), page.url());
        assert.equal(historyUrl.pathname, '/protokoll.php');
        assert.deepEqual(Object.fromEntries(historyUrl.searchParams), {
            dokid:'1', ereignis:'metadaten_geaendert', regel:'R0001', seite:'2', return:back
        });
        const formData=await page.locator('.ea-history-toolbar').evaluate(form=>Object.fromEntries(new FormData(form)));
        assert.equal(formData.dokid,'1');assert.equal(formData.ereignis,'metadaten_geaendert');assert.equal(formData.regel,'R0001');assert.equal(formData.return,back);
        for(const label of ['Neuere Ereignisse','Ältere Ereignisse']) {
            const params=new URL(await footer.getByRole('link',{name:label}).getAttribute('href'),'http://eablage.test').searchParams;
            assert.equal(params.get('dokid'),'1');assert.equal(params.get('ereignis'),'metadaten_geaendert');
            assert.equal(params.get('regel'),'R0001');assert.equal(params.get('return'),back);
        }
        await scroll.evaluate(el=>el.scrollTop=el.scrollHeight);
        assert.ok(await scroll.evaluate(el=>el.scrollTop>0));
        assert.deepEqual(await heading.boundingBox(),titleBefore);assert.deepEqual(await footer.boundingBox(),footerBefore);
        if(process.env.UI_SCREENSHOT_DIR) {
            await scroll.evaluate(el=>el.scrollTop=0);
            await page.screenshot({path:path.join(process.env.UI_SCREENSHOT_DIR,`history-${width}-${layout}.png`)});
        }
    } finally {await page.close();}
});
for(const [width,height] of [[390,900],[800,900],[1440,550]]) test(`${width}x${height}: event history retains page scrolling`,async()=>{
    const page=await open('protokoll.php?layout=touch',width);
    try {
        await page.setViewportSize({width,height});
        await page.locator('.ea-history-scroll').evaluate(el=>{const source=el.querySelector('details');for(let i=0;i<20;i++)el.append(source.cloneNode(true));});
        assert.equal(await page.locator('.ea-history-scroll').evaluate(el=>getComputedStyle(el).overflowY),'visible');
        await page.locator('.ea-history-footer').scrollIntoViewIfNeeded();
        assert.ok(await page.evaluate(()=>document.documentElement.scrollHeight>innerHeight));
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    } finally {await page.close();}
});
for(const [query,expected] of [['fixture_count=0','Keine Ereignisse'],['dokid=ungueltig','gültige DokID']]) test(`event history: empty or invalid filter ${query} keeps return accessible`,async()=>{
    const page=await open('protokoll.php?layout=desktop&'+query,1920);
    try {
        assert.match(await page.locator('.ea-history-scroll').innerText(),new RegExp(expected));
        assert.equal(await page.locator('.ea-history-footer').getByRole('link',{name:'Zurück',exact:true}).isVisible(),true);
        assert.equal(await page.locator('.ea-history-footer').getByRole('link',{name:'Ältere Ereignisse'}).count(),0);
        assert.ok(await page.evaluate(()=>document.documentElement.scrollHeight<=innerHeight+1));
    } finally {await page.close();}
});

test('trash wraps long subjects inside the panel on small and wide screens', async () => {
    for (const [width,layout] of [[390,'touch'],[1440,'touch'],[1440,'desktop']]) {
        const page = await open('papierkorb.php?layout='+layout, width);
        try {
            const row = page.locator('.ea-trash-entry');
            const subject = row.locator('.ea-inbox-filename');
            const textFits = await subject.evaluate(el => el.scrollWidth <= el.clientWidth+1);
            assert.ok(textFits, 'long subject wraps without clipping');
            const bounds = await row.boundingBox();
            for (const control of await row.locator('button, input[required]').all()) {
                const box = await control.boundingBox();
                assert.ok(box.x >= bounds.x && box.x + box.width <= bounds.x + bounds.width + 1);
            }
        } finally { await page.close(); }
    }
});

test('case help in document editing opens on focus and closes with Escape', async () => {
    const page = await open('bearbeiten.php?dokid=1&layout=desktop');
    try {
        const help = page.getByRole('button', {name:'Hinweis zu Vorgängen'});
        const tooltip = page.getByRole('tooltip');
        await help.scrollIntoViewIfNeeded();
        await help.focus();
        await tooltip.waitFor({state:'visible'});
        assert.match(await tooltip.textContent(), /Zuordnung wird mit dem Dokument gespeichert/);
        await help.press('Escape');
        await tooltip.waitFor({state:'hidden'});
        assert.equal(await help.getAttribute('aria-expanded'), 'false');
    } finally { await page.close(); }
});

test('trash and export scroll inside the workspace on large screens', async () => {
    for (const [width, layout] of [[900, 'touch'], [1440, 'desktop']]) {
        for (const route of ['papierkorb.php?', 'gesamtexport.php?bereich=archiv&']) {
            const page = await open(route + 'layout=' + layout, width);
            try {
                const scroll = page.locator('.ea-admin-workspace-scroll');
                const heading = page.locator('.ea-admin-workspace-panel > .panel-heading');
                const tabs = page.locator('.ea-admin-workspace-tabs');
                const headingBefore = await heading.boundingBox();
                const tabsBefore = await tabs.count() ? await tabs.boundingBox() : null;
                await scroll.evaluate(el => {
                    const row = el.firstElementChild;
                    for (let i = 0; i < 20; i++) el.append(row.cloneNode(true));
                    el.scrollTop = el.scrollHeight;
                });
                assert.ok(await scroll.evaluate(el => el.scrollTop > 0));
                assert.ok(await page.evaluate(() => document.documentElement.scrollHeight <= innerHeight + 1));
                assert.deepEqual(await heading.boundingBox(), headingBefore);
                if (tabsBefore) assert.deepEqual(await tabs.boundingBox(), tabsBefore);
            } finally { await page.close(); }
        }
    }
});

test('export areas keep actions and POST results together', async () => {
    for (const [area, name, action, button, message] of [
        ['export', 'Gesamtexport', 'export', 'Gesamtexport starten', 'noch kein Zielverzeichnis'],
        ['sicherung', 'Datensicherung', 'backup', 'Datenbanksicherung erstellen', 'noch kein Zielverzeichnis'],
        ['archiv', 'Archivprüfung', 'archive_audit', 'Archiv prüfen', 'Keine Abweichungen gefunden'],
    ]) {
        const page = await open('gesamtexport.php?layout=touch', 390, {action});
        try {
            const tabs = page.getByRole('navigation', {name: 'Export und Datensicherung'});
            assert.equal(await tabs.locator('[aria-current=page]').innerText(), name);
            assert.equal(await tabs.getByRole('link', {name, exact:true}).getAttribute('href'), 'gesamtexport.php?bereich='+area);
            const region = page.getByRole('region', {name, exact:true});
            assert.equal(await region.getByRole('button', {name:button}).count(), 1);
            assert.equal(await region.locator('form').count(), 1);
            assert.ok((await region.innerText()).includes(message));
            assert.equal(await region.evaluate(el => getComputedStyle(el).overflowY), 'visible');
        } finally { await page.close(); }
    }
});

test('case overview exposes sorting and retains search and order across pages', async () => {
    const page = await open('vorgaenge.php?q=Heizung&sort=date&fixture_count=52');
    try {
        const list = page.getByRole('table', {name:'Vorgänge'});
        assert.equal(await list.locator('time').innerText(), '10.11.2026');
        const dateHeader = list.getByRole('columnheader', {name:'Angelegt am'});
        assert.equal(await dateHeader.getAttribute('aria-sort'), 'descending');
        const query = async link => new URL(await link.getAttribute('href'), page.url()).searchParams;
        const reverse = await query(dateHeader.getByRole('link'));
        assert.equal(reverse.get('sort'), 'date-asc');
        assert.equal(reverse.get('q'), 'Heizung');
        assert.equal(reverse.get('page'), '1');
        const next = await query(page.getByRole('link', {name:'Nächste »'}));
        assert.equal(next.get('sort'), 'date');
        assert.equal(next.get('q'), 'Heizung');
        assert.equal(next.get('page'), '2');
        assert.equal(await page.locator('.ea-vorgang-search [name="sort"]').inputValue(), 'date');
        await page.setViewportSize({width:390,height:900});
        assert.equal(await dateHeader.isVisible(), false);
        assert.equal(await page.getByLabel('Sortieren nach').inputValue(), 'date');
        assert.equal(await page.getByRole('button', {name:'Sortieren',exact:true}).isVisible(), true);
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    } finally { await page.close(); }
});
