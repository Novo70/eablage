// Render the real PHP master-data page with a simulated backend, then exercise its row actions.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {spawnSync} = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
async function setup(t, kind, width=1440, desktop=false, withRules=false) {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'eablage-master-'));
    t.after(()=>fs.rmSync(dir,{recursive:true,force:true}));
    for(const name of ['stammdaten.php','icons.php','csrf.php','meldungen.php','status_toggle.php','master_data_sort.js','master_data_layout.js','help_tooltip.js']) fs.copyFileSync(path.join(root,'web',name),path.join(dir,name));
    fs.writeFileSync(path.join(dir,'konfiguration.php'),"<?php define('MYSQL_DATENBANK','testdb');");
    fs.writeFileSync(path.join(dir,'navbar.php'),'');
    fs.copyFileSync(path.join(root,'web/app.css'),path.join(dir,'app.css'));
    fs.copyFileSync(path.join(root,'web/status_toggle.js'),path.join(dir,'status_toggle.js'));
    fs.writeFileSync(path.join(dir,'funktionen.php'),`<?php
function eablage_backend_request($method,$url,$payload,$timeout) {
    return ['ok'=>true,'http_status'=>200,'data'=>['items'=>[
        ['sort_order'=>20,'id'=>'10','name'=>'Haus & Garten','active'=>1,'documents'=>2,'rules'=>${withRules ? "[['RegelID'=>'R0001','Bezeichnung'=>'Testregel','Aktiv'=>1]]" : '[]'}, 'explanation'=>"Gebäudeversicherung\n</textarea><script>unsafe()</script>"],
        ['sort_order'=>10,'id'=>'11','name'=>'Ohne Erläuterung','active'=>1,'documents'=>0,'rules'=>[], 'explanation'=>'']
    ]]];
}`);
    fs.writeFileSync(path.join(dir,'run.php'),`<?php $_SERVER['REQUEST_METHOD']='GET'; $_GET['kind']=$argv[1]; require 'stammdaten.php';`);
    const rendered = spawnSync(process.env.PHP_BIN || 'php',['-n','-d','session.save_path='+dir,path.join(dir,'run.php'),kind],{cwd:dir,encoding:'utf8'});
    assert.equal(rendered.status,0,rendered.stderr);
    const browser = await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_EXECUTABLE_PATH,args:['--no-sandbox']});
    t.after(()=>browser.close());
    const page = await browser.newPage({viewport:{width,height:900}});
    await page.route('http://eablage.test/**', route=>route.request().url().endsWith('/icons.svg') ? route.fulfill({contentType:'image/svg+xml',body:fs.readFileSync(path.join(root,'web/icons.svg'))}) : route.fulfill({contentType:'text/html',body:rendered.stdout}));
    await page.goto('http://eablage.test/stammdaten.php');
    await page.addStyleTag({content:fs.readFileSync(path.join(root,'web/app.css'),'utf8')});
    if(desktop) { await page.evaluate(()=>document.documentElement.dataset.eaLayout='desktop'); await page.addStyleTag({content:fs.readFileSync(path.join(root,'web/desktop.css'),'utf8')}); }
    await page.addScriptTag({content:fs.readFileSync(path.join(root,'web/master_data_layout.js'),'utf8')});
    await page.addScriptTag({content:fs.readFileSync(path.join(root,'web/help_tooltip.js'),'utf8')});
    await page.addScriptTag({content:fs.readFileSync(path.join(root,'web/status_toggle.js'),'utf8')});
    if (kind==='number_types') await page.addScriptTag({content:fs.readFileSync(path.join(root,'web/master_data_sort.js'),'utf8')});
    return page;
}
for(const kind of ['categories','document_types']) test(kind+': optional explanation renders safely and saves with name',async t=>{
    const page = await setup(t,kind);
    assert.deepEqual((await page.locator('thead th').allTextContents()).map(text=>text.replace(/\?/g,'').trim()),['Bezeichnung','Erläuterung','Verwendung','Aktionen','Status']);
    const form = page.locator('#master-edit-3130');
    assert.equal(await page.locator('textarea[form="master-edit-3130"]').inputValue(),'Gebäudeversicherung\n</textarea><script>unsafe()</script>');
    assert.equal(await page.locator('script').filter({hasText:'unsafe()'}).count(),0);
    assert.equal(await page.locator('#new-explanation').getAttribute('required'),null);
    await page.locator('textarea[form="master-edit-3130"]').fill('Immobilien\nGebäudeversicherung');
    let fields;
    await page.route('**/stammdaten.php',route=>{
        fields=route.request().postData();
        return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,action:'rename'})});
    });
    const button=page.locator('button[form="master-edit-3130"]');
    await button.click();
    await page.waitForFunction(()=>document.querySelector('button[form="master-edit-3130"]').title === 'Alle Änderungen gespeichert' && !document.querySelector('button[form="master-edit-3130"]').disabled);
    assert.match(fields,/name="explanation"\r\n\r\nImmobilien\r\nGebäudeversicherung/);
    assert.match(fields,new RegExp('name="kind"\\r\\n\\r\\n'+kind));
    assert.equal(await page.locator('tr:has(#master-edit-3130) .ea-master-explanation summary').textContent(),'Erläuterung (vorhanden)');
    await page.locator('textarea[form="master-edit-3130"]').fill('');
    await button.click();
    await page.waitForFunction(()=>document.querySelector('tr:has(#master-edit-3130) .ea-master-explanation summary').textContent.includes('optional'));
});

test('number types: name precedes sort column and sort order saves with the row',async t=>{
    const page = await setup(t,'number_types');
    assert.deepEqual((await page.locator('thead th').allTextContents()).map(text=>text.replace(/\?/g,'').trim()),['Bezeichnung','Erläuterung','Sortierung ↑','Verwendung','Aktionen','Status']);
    const sort=page.locator('input[name="sort_order"][form="master-edit-3130"]');
    const name=page.locator('#master-edit-3130 input[name="name"]');
    const explanation=page.locator('textarea[form="master-edit-3130"]');
    const sortBox=await sort.boundingBox(), nameBox=await name.boundingBox(), explanationBox=await explanation.boundingBox();
    assert.ok(nameBox.x+nameBox.width <= sortBox.x);
    assert.ok(Math.abs(sortBox.y-nameBox.y)<2);
    assert.ok(Math.abs(explanationBox.y-nameBox.y)<2);
    assert.ok(nameBox.width<explanationBox.width);
    await page.locator('#master-create > summary').click();
    const newName=await page.locator('#new-name').boundingBox(), newSort=await page.locator('#new-sort-order').boundingBox();
    assert.ok(Math.abs(newName.y-newSort.y)<2);
    await sort.fill('25');
    await name.fill('Vertragsnummer');
    let fields;
    await page.route('**/stammdaten.php',route=>{
        fields=route.request().postData();
        return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,action:'rename'})});
    });
    await page.locator('button[form="master-edit-3130"]').click();
    await page.waitForFunction(()=>document.querySelector('button[form="master-edit-3130"]').title === 'Alle Änderungen gespeichert' && !document.querySelector('button[form="master-edit-3130"]').disabled);
    assert.match(fields,/name="sort_order"\r\n\r\n25/);
    assert.match(fields,/name="name"\r\n\r\nVertragsnummer/);
});
test('number type headings sort locally and retain unsaved form fields and open explanations',async t=>{
    const page = await setup(t,'number_types');
    const names=()=>page.locator('tbody input[name="name"]').evaluateAll(inputs=>inputs.map(input=>input.value));
    assert.deepEqual(await names(),['Ohne Erläuterung','Haus & Garten']);
    let posts=0;
    page.on('request',request=>{if(request.method()==='POST')posts++;});
    await page.locator('[data-sort-key="name"]').click();
    assert.deepEqual(await names(),['Haus & Garten','Ohne Erläuterung']);
    assert.equal(await page.locator('th:has([data-sort-key="name"])').getAttribute('aria-sort'),'ascending');
    assert.equal(await page.locator('th:has([data-sort-key="sort_order"])').getAttribute('aria-sort'),'none');
    const form=page.locator('#master-edit-3130');
    await page.locator('textarea[form="master-edit-3130"]').fill('Noch nicht gespeichert');
    await page.locator('input[form="master-edit-3130"][name="sort_order"]').fill('10');
    await page.locator('[data-sort-key="sort_order"]').focus();
    await page.keyboard.press('Enter');
    assert.deepEqual(await names(),['Haus & Garten','Ohne Erläuterung']);
    assert.equal(await page.locator('textarea[form="master-edit-3130"]').inputValue(),'Noch nicht gespeichert');
    assert.equal(await page.locator('tr:has(#master-edit-3130) .ea-master-explanation').getAttribute('open'),'');
    await page.locator('input[form="master-edit-3130"][name="sort_order"]').fill('30');
    await page.locator('[data-sort-key="sort_order"]').click();
    assert.deepEqual(await names(),['Ohne Erläuterung','Haus & Garten']);
    assert.equal(await page.locator('input[form="master-edit-3130"][name="sort_order"]').inputValue(),'30');
    assert.equal(await page.locator('#master-edit-3130').evaluate(form=>form.closest('tr').nextElementSibling.classList.contains('ea-row-feedback')),true);
    assert.equal(posts,0);
});
test('number sort edits during and after saving keep the unsaved state visible',async t=>{
    const page = await setup(t,'number_types');
    const sort=page.locator('input[name="sort_order"][form="master-edit-3130"]');
    const button=page.locator('button[form="master-edit-3130"]');
    let reply, requested;
    const pending=new Promise(resolve=>requested=resolve);
    await page.route('**/stammdaten.php',async route=>{
        requested();
        await new Promise(resolve=>reply=resolve);
        return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,action:'rename'})});
    });
    await sort.fill('25');await button.click();await pending;
    await sort.fill('26');reply();
    await page.waitForFunction(()=>!document.querySelector('button[form="master-edit-3130"]').disabled);
    assert.equal(await button.textContent(),'Speichern');
    assert.equal(await button.evaluate(el=>el.classList.contains('ea-master-save-dirty')),true);
    await sort.fill('25');
    assert.equal(await button.evaluate(el=>el.classList.contains('ea-master-save-dirty')),false);
    await sort.fill('26');
    await page.unroute('**/stammdaten.php');
    await page.route('**/stammdaten.php',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,action:'rename'})}));
    await button.click();
    await page.waitForFunction(()=>document.querySelector('button[form="master-edit-3130"]').title === 'Alle Änderungen gespeichert' && !document.querySelector('button[form="master-edit-3130"]').disabled);
    await sort.fill('27');assert.equal(await button.textContent(),'Speichern');
    assert.equal(await button.evaluate(el=>el.classList.contains('ea-master-save-dirty')),true);
});

for(const kind of ['categories','document_types','number_types']) {
    for(const [width,desktop] of [[900,false],[1440,false],[1920,true]]) test(`${kind}: compact columns at ${width}px ${desktop?'desktop':'standard'}`,async t=>{
        const page=await setup(t,kind,width,desktop);
        const row=page.locator('tr:has(#master-edit-3130)');
        const name=await row.locator('[name="name"]').boundingBox();
        const explanation=await row.locator('textarea').boundingBox();
        assert.ok(name.width<explanation.width,'explanation gets more space than name');
        assert.ok(name.x+name.width<explanation.x,'fields sit next to each other');
        assert.ok(Math.abs(name.y-explanation.y)<2,'fields align at top');
        assert.equal(await row.locator('.ea-master-explanation summary').isVisible(),false);
        assert.equal(await row.locator('textarea').getAttribute('rows'),'2');
        assert.equal(await row.locator('.ea-master-rule-count').evaluate(el=>getComputedStyle(el).whiteSpace),'nowrap');
        assert.ok((await row.locator('.ea-master-actions button[form]').boundingBox()).width<120,'save button follows its label');
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no horizontal page overflow');
        const widths=()=>row.locator('.ea-master-usage-cell,.ea-master-actions-cell,.ea-master-status-cell').evaluateAll(cells=>cells.map(cell=>cell.getBoundingClientRect().width));
        const before=await widths();
        await page.setViewportSize({width:width+200,height:900});
        const after=await widths();
        before.forEach((value,index)=>assert.ok(Math.abs(value-after[index])<=1,'utility columns keep their width'));
    });
}

test('mobile explanation keeps its state and unsaved text across viewport changes',async t=>{
    const page=await setup(t,'categories',800);
    const resize=async width=>{
        await page.setViewportSize({width,height:900});
        await page.waitForFunction(()=>document.querySelector('.ea-master-table').hasAttribute('data-master-wide')===(innerWidth>=900));
    };
    const details=page.locator('tr:has(#master-edit-3130) .ea-master-explanation');
    const field=details.locator('textarea');
    assert.equal(await details.getAttribute('open'),null);
    await details.locator('summary').click();
    await field.fill('Noch nicht gespeichert');
    assert.equal(await page.locator('#master-edit-3130').evaluate(form=>new FormData(form).get('explanation')),'Noch nicht gespeichert');
    await resize(1440);
    assert.equal(await field.isVisible(),true);
    assert.equal(await details.locator('summary').isVisible(),false);
    await resize(800);
    assert.equal(await details.getAttribute('open'),'');
    assert.equal(await field.inputValue(),'Noch nicht gespeichert');
    await details.locator('summary').click();
    await resize(1440);
    await resize(800);
    assert.equal(await details.getAttribute('open'),null);
    assert.equal(await field.inputValue(),'Noch nicht gespeichert');
});

test('explanation edits after saving mark the row unsaved',async t=>{
    const page=await setup(t,'categories');
    const field=page.locator('textarea[form="master-edit-3130"]');
    const button=page.locator('button[form="master-edit-3130"]');
    await page.route('**/stammdaten.php',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,action:'rename'})}));
    await field.fill('Gespeicherte Erläuterung');
    await button.click();
    await page.waitForFunction(()=>document.querySelector('button[form="master-edit-3130"]').title === 'Alle Änderungen gespeichert' && !document.querySelector('button[form="master-edit-3130"]').disabled);
    await field.fill('Weitere Änderung');
    assert.equal(await button.textContent(),'Speichern');
    assert.equal(await button.evaluate(el=>el.classList.contains('ea-master-save-dirty')),true);
});

for(const kind of ['categories','document_types','number_types']) {
    for(const desktop of [false,true]) test(`${kind}: headers stick below tabs in ${desktop?'desktop':'standard'} layout`,async t=>{
        const page=await setup(t,kind,desktop?1920:1440,desktop);
        await page.locator('.ea-master-table tbody').evaluate(body=>{
            const row=body.querySelector('tr'); for(let i=0;i<35;i++) body.append(row.cloneNode(true));
        });
        const scroll=page.locator('.ea-master-scroll');
        const tabs=await page.locator('.ea-tabs').boundingBox();
        await scroll.evaluate(el=>el.scrollTop=500);
        const box=await scroll.boundingBox();
        for(const th of await page.locator('thead th').all()) {
            const heading=await th.boundingBox();
            assert.ok(Math.abs(heading.y-box.y)<2,'table header stays at top of inner scroll area');
            assert.ok(heading.y>=tabs.y+tabs.height-1,'header stays below tabs');
        }
    });
}
for(const width of [900,1440]) test(`${width}px: usage has two lines and keeps AutoKat-Regeln together`,async t=>{
    const page=await setup(t,'number_types',width,false,true);
    const cell=page.locator('tr:has(#master-edit-3130) .ea-master-usage-cell');
    const documents=await cell.locator(':scope > div').boundingBox();
    const rules=cell.locator('summary');
    const ruleBox=await rules.boundingBox();
    assert.ok(ruleBox.y>=documents.y+documents.height-1,'rules follow document count');
    assert.equal(await rules.innerText(),'1 AutoKat-Regeln');
    assert.ok(await rules.evaluate(el=>el.scrollWidth<=el.clientWidth+1),'rule label fits without wrapping or clipping');
    await rules.click();
    assert.equal(await cell.getByRole('link',{name:'R0001',exact:true}).isVisible(),true);
});
for(const width of [390,1440]) test(`${width}px: help works with hover, keyboard and tap`,async t=>{
    const page=await setup(t,'number_types',width);
    const help=page.getByRole('button',{name:'Hinweis zu Bezeichnung',exact:true}).filter({visible:true});
    const tooltip=page.getByRole('tooltip');
    let posts=0; page.on('request',request=>{if(request.method()==='POST')posts++;});
    await help.hover();
    assert.equal(await tooltip.innerText(),'Umbenennen gilt auch für bestehende Dokumente.');
    await page.keyboard.press('Escape');
    assert.equal(await tooltip.isVisible(),false);
    await help.focus();
    assert.equal(await tooltip.isVisible(),true);
    await help.click();
    const box=await tooltip.boundingBox();
    assert.ok(box.x>=0 && box.x+box.width<=width,'tooltip fits viewport');
    await page.keyboard.press('Escape');
    await page.getByRole('button',{name:'Hinweis zu Status',exact:true}).filter({visible:true}).click();
    assert.match(await tooltip.innerText(),/Deaktivierte Einträge bleiben recherchierbar/);
    await page.getByRole('button',{name:'Hinweis zu Aktionen',exact:true}).filter({visible:true}).click();
    assert.equal(await tooltip.innerText(),'Löschen ist nur ohne Dokumentverknüpfungen und ohne Regelverknüpfungen möglich.');
    await page.getByRole('button',{name:'Hinweis zu Erläuterung',exact:true}).filter({visible:true}).click();
    assert.equal(await tooltip.innerText(),'Die Erläuterung dient der eigenen Dokumentation und hilft der KI, Dokumente passend zu klassifizieren.');
    assert.equal(posts,0,'help never saves or sorts a row');
});

for(const kind of ['categories','document_types','number_types']) {
    for(const [width,desktop] of [[1440,false],[1920,false],[1440,true],[1920,true]]) test(`${kind}: ${desktop?'desktop':'standard'} row actions stay side by side at ${width}px when edited and saved`,async t=>{
        const page=await setup(t,kind,width,desktop);
        const actions=page.locator('tr:has(#master-edit-3130) .ea-master-actions');
        const save=actions.locator('button[form]');
        const remove=actions.locator('form button');
        const assertOneLine=async()=>{
            const left=await save.boundingBox(), right=await remove.boundingBox();
            const cell=await actions.boundingBox();
            assert.ok(Math.abs(left.y-right.y)<2,'save and delete share one line: '+JSON.stringify({left,right}));
            assert.ok(left.x+left.width<=right.x,'buttons do not overlap');
            assert.ok(right.x+right.width<=cell.x+cell.width+1,'buttons fit their column');
        };
        await assertOneLine();
        const initialWidth=(await save.boundingBox()).width;
        const initialColor=await save.evaluate(el=>getComputedStyle(el).backgroundColor);
        const field=page.locator('#master-edit-3130 input[name="name"]');
        await field.fill('Geänderte Bezeichnung');
        assert.equal(await save.evaluate(el=>el.classList.contains('ea-master-save-dirty')),true);
        await assertOneLine();
        await page.waitForFunction(color=>getComputedStyle(document.querySelector('button[form="master-edit-3130"]')).backgroundColor!==color,initialColor);
        assert.equal((await save.boundingBox()).width,initialWidth);
        await page.route('**/stammdaten.php',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,action:'rename'})}));
        await save.click();
        await page.waitForFunction(()=>document.querySelector('button[form="master-edit-3130"]').title === 'Alle Änderungen gespeichert' && !document.querySelector('button[form="master-edit-3130"]').disabled);
        await assertOneLine();
        assert.equal(await save.textContent(),'Speichern');
        assert.equal(await save.evaluate(el=>el.classList.contains('ea-master-save-dirty')),false);
        assert.equal((await save.boundingBox()).width,initialWidth);
    });
}
