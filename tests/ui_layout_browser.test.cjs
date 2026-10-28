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
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
    $aktionToken='test-token'; $datei='Versicherung.pdf'; $vollstaendigerPfad=__FILE__;
    $dokumentText=str_repeat("Beispieltext der Gebäudeversicherung.\\n", 100);
    $sourceSHA256=str_repeat('a',64); $erkennungsInfo='Manuell'; $job=null;
    $ungueltigeVorschlaege=$weitereKlassifikationsregeln=$autokatQuellen=$textvergleichQuellen=[];
    $jobResponse=['ok'=>true]; $pdfTextResult=['ok'=>true]; $klassifizierung=['numbers'=>[['kind_id'=>'1','value'=>'AB-123/45']]];
    $ollamaAvailable=$ollamaDiagnosticEnabled=$backendFehler=$erkennungsDetailsOffen=$pythonAutoKatErfolgreich=$pythonTextvergleichErfolgreich=false;
    $regelVorbereitungMoeglich=false; $pythonAutoKat=$pythonTextvergleich=[];
    $erkannterPartner='Müller & Söhne'; $erkannterBetreff='Gebäudeversicherung'; $erkanntesDatum='2026-10-26';
    $erkannteKategorie=$erkannteKategorie2=$erkanntesDoktyp='1'; $erkannteNummer='AB-123/45'; $erkannteTags='Haus';
    $kategorien=$kategorien2=eablage_lade_kategorien($db); $dokumenttypen=eablage_lade_dokumenttypen($db);
    if ($page==='bearbeiten.php') {
        function changed_marker(...$args) { return ''; }
        $saved=$textReloaded=$reclassified=false; $error=$reclassificationInfo=$pendingDocumentText='';
        $documentRevision='original-revision'; $reclassificationChanged=[]; $dokId=1;
        $returnUrl=$_GET['return'] ?? 'dokumente.php'; $pdfLink='archiv_pdf.php?dokid=1';
        $displayDocumentText=$storedDocumentText=$dokumentText; $filename='Versicherung.pdf';
        $document=$storedDocument=['DokID'=>1,'Datum'=>'2026-10-26','Partner'=>$erkannterPartner,'Betreff'=>$erkannterBetreff,'Tags'=>'Haus','doktyp_id'=>'1','kategorie_id'=>'1','kategorie2_id'=>'0','numbers'=>$klassifizierung['numbers']];
        require 'editing-view.php';
    } else { require 'classification-view.php'; }
} else { require $page; }
`);
    browser = await chromium.launch({headless:true, executablePath:process.env.PLAYWRIGHT_EXECUTABLE_PATH, args:['--no-sandbox']});
    context = await browser.newContext({locale:'de-DE'});
});
after(async () => { if (browser) await browser.close(); if (dir) fs.rmSync(dir, {recursive:true,force:true}); });
async function open(url, width=1440, post=null) {
    const args=['-d','session.save_path='+dir, path.join(dir,'run.php'),url];
    if (post) args.push(JSON.stringify(post));
    const rendered=spawnSync(process.env.PHP_BIN || 'php', args, {cwd:dir,encoding:'utf8'});
    assert.equal(rendered.status,0,rendered.stderr);
    assert.equal(rendered.stderr,'');
    const page=await context.newPage();
    await page.setViewportSize({width,height:900});
    await page.route('http://eablage.test/**', async route => {
        const name=path.basename(new URL(route.request().url()).pathname);
        if (name.endsWith('.php') && route.request().frame() === page.mainFrame()) return route.fulfill({contentType:'text/html',body:rendered.stdout});
        // The embedded PDF viewer is browser-specific; never recursively serve the application in it.
        if (name.endsWith('.php')) return route.fulfill({contentType:'text/plain',body:'Test-PDF – Vorschau separat mit einem echten Dokument prüfen.'});
        const file=path.join(dir,name);
        if (fs.existsSync(file)) return route.fulfill({path:file});
        return route.fulfill({status:404,body:''});
    });
    await page.goto('http://eablage.test/'+url);
    await page.evaluate(()=>document.fonts.ready);
    return page;
}
const views=['index.php','dokumente.php','stammdaten.php?kind=categories','stammdaten.php?kind=document_types','stammdaten.php?kind=number_types','einstellungen.php','explorer.php','verarbeiten.php','bearbeiten.php'];
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
test('navigation shows the current work area and closes with Escape, focus and outside clicks', async () => {
    const page=await open('dokumente.php?Suchbegriff=Haus');
    try {
        assert.equal(await page.locator('.ea-navbar__links [aria-current="page"]').innerText(),'Suche');
        await page.locator('#eaMenuBtn').click();
        assert.equal(await page.locator('#eaMenuBtn').getAttribute('aria-expanded'),'true');
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('#eaMenuBtn').getAttribute('aria-expanded'),'false');
        assert.equal(await page.locator('#eaMenuBtn').evaluate(el=>el===document.activeElement),true);
        await page.locator('#eaMenuBtn').click();
        assert.match(await page.locator('#eaMenuDropdown a[href^="einstellungen.php"]').getAttribute('href'), /return=dokumente.php%3FSuchbegriff%3DHaus/);
        await page.locator('#Suchbegriff').focus();
        assert.equal(await page.locator('#eaMenuBtn').getAttribute('aria-expanded'),'false');
        await page.locator('#eaMenuBtn').click();
        await page.locator('.panel-heading').first().click();
        assert.equal(await page.locator('#eaMenuBtn').getAttribute('aria-expanded'),'false');
    } finally { await page.close(); }
});
test('classification keeps safe form actions and a working PDF/text switch', async () => {
    const page=await open('verarbeiten.php');
    try {
        assert.deepEqual(await page.locator('.ea-classification-actions .btn').allTextContents(),['Abbrechen','💾 Speichern']);
        await page.locator('#classification-more-actions summary').click();
        const discard=page.locator('#classification-more-actions button[form="discard-inbox-file-form"]');
        let posts=0; page.on('request',r=>{if(r.method()==='POST')posts++;});
        page.once('dialog',d=>d.dismiss()); await discard.click();
        assert.equal(posts,0);
        await page.locator('#viewTextButton').click();
        assert.equal(await page.locator('#textDocumentView').isVisible(),true);
        await page.locator('#viewPdfButton').click();
        assert.equal(await page.locator('#textDocumentView').isVisible(),false);
        await page.locator('#classification-more-actions summary').click();
        page.once('dialog',d=>d.accept());
        const request=page.waitForRequest(r=>r.method()==='POST');
        await discard.click();
        const submitted=await request;
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
            assert.equal(await page.locator('.pagination-wrapper').count(),count>50 ? 2 : 0);
            if(count>50) {
                const next=new URL(await page.locator('.pagination-wrapper a').first().getAttribute('href'),page.url());
                assert.equal(next.searchParams.get('page'),'2');
                assert.equal(next.searchParams.get('Suchbegriff'),'Haus');
            }
            if(count===0) assert.match(await page.locator('.alert-warning').textContent(),/Keine Dokumente/);
        } finally { await page.close(); }
    }
});
