// Render the real PHP master-data page with a simulated backend, then exercise its row actions.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {spawnSync} = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
async function setup(t, kind) {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'eablage-master-'));
    t.after(()=>fs.rmSync(dir,{recursive:true,force:true}));
    for(const name of ['stammdaten.php','csrf.php','meldungen.php','status_toggle.php','master_data_sort.js']) fs.copyFileSync(path.join(root,'web',name),path.join(dir,name));
    fs.writeFileSync(path.join(dir,'konfiguration.php'),"<?php define('MYSQL_DATENBANK','testdb');");
    fs.writeFileSync(path.join(dir,'navbar.php'),'');
    fs.copyFileSync(path.join(root,'web/app.css'),path.join(dir,'app.css'));
    fs.copyFileSync(path.join(root,'web/status_toggle.js'),path.join(dir,'status_toggle.js'));
    fs.writeFileSync(path.join(dir,'funktionen.php'),`<?php
function eablage_backend_request($method,$url,$payload,$timeout) {
    return ['ok'=>true,'http_status'=>200,'data'=>['items'=>[
        ['sort_order'=>20,'id'=>'10','name'=>'Haus & Garten','active'=>1,'documents'=>2,'rules'=>[], 'explanation'=>"Gebäudeversicherung\n</textarea><script>unsafe()</script>"],
        ['sort_order'=>10,'id'=>'11','name'=>'Ohne Erläuterung','active'=>1,'documents'=>0,'rules'=>[], 'explanation'=>'']
    ]]];
}`);
    fs.writeFileSync(path.join(dir,'run.php'),`<?php $_SERVER['REQUEST_METHOD']='GET'; $_GET['kind']=$argv[1]; require 'stammdaten.php';`);
    const rendered = spawnSync(process.env.PHP_BIN || 'php',['-n','-d','session.save_path='+dir,path.join(dir,'run.php'),kind],{cwd:dir,encoding:'utf8'});
    assert.equal(rendered.status,0,rendered.stderr);
    const browser = await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_EXECUTABLE_PATH,args:['--no-sandbox']});
    t.after(()=>browser.close());
    const page = await browser.newPage();
    await page.route('http://eablage.test/**', route=>route.fulfill({contentType:'text/html',body:rendered.stdout}));
    await page.goto('http://eablage.test/stammdaten.php');
    await page.addStyleTag({content:fs.readFileSync(path.join(root,'web/app.css'),'utf8')});
    await page.addScriptTag({content:fs.readFileSync(path.join(root,'web/status_toggle.js'),'utf8')});
    if (kind==='number_types') await page.addScriptTag({content:fs.readFileSync(path.join(root,'web/master_data_sort.js'),'utf8')});
    return page;
}
for(const kind of ['categories','document_types']) test(kind+': optional explanation renders safely and saves with name',async t=>{
    const page = await setup(t,kind);
    assert.deepEqual(await page.locator('thead th').allTextContents(),['Bezeichnung / Erläuterung','Verwendung','Aktionen','Status']);
    const form = page.locator('#master-edit-3130');
    assert.equal(await form.locator('textarea').inputValue(),'Gebäudeversicherung\n</textarea><script>unsafe()</script>');
    assert.equal(await page.locator('script').filter({hasText:'unsafe()'}).count(),0);
    assert.equal(await page.locator('#new-explanation').getAttribute('required'),null);
    await form.locator('summary').click();
    await form.locator('textarea').fill('Immobilien\nGebäudeversicherung');
    let fields;
    await page.route('**/stammdaten.php',route=>{
        fields=route.request().postData();
        return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,action:'rename'})});
    });
    const button=page.locator('button[form="master-edit-3130"]');
    await button.click();
    await page.waitForFunction(()=>document.querySelector('button[form="master-edit-3130"]').textContent.includes('Gespeichert'));
    assert.match(fields,/name="explanation"\r\n\r\nImmobilien\r\nGebäudeversicherung/);
    assert.match(fields,new RegExp('name="kind"\\r\\n\\r\\n'+kind));
    assert.equal(await form.locator('summary').innerText(),'Erläuterung (vorhanden)');
    await form.locator('textarea').fill('');
    await button.click();
    await page.waitForFunction(()=>document.querySelector('#master-edit-3130 summary').textContent.includes('optional'));
});

test('number types: name precedes sort column and sort order saves with the row',async t=>{
    const page = await setup(t,'number_types');
    assert.deepEqual(await page.locator('thead th').allTextContents(),['Bezeichnung / Erläuterung','Sortierung ↑','Verwendung','Aktionen','Status']);
    const sort=page.locator('input[name="sort_order"][form="master-edit-3130"]');
    const name=page.locator('#master-edit-3130 input[name="name"]');
    const explanation=page.locator('#master-edit-3130 summary');
    const sortBox=await sort.boundingBox(), nameBox=await name.boundingBox(), explanationBox=await explanation.boundingBox();
    assert.ok(nameBox.x+nameBox.width <= sortBox.x);
    assert.ok(Math.abs(sortBox.y-nameBox.y)<2);
    assert.ok(explanationBox.y >= nameBox.y+nameBox.height);
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
    await page.waitForFunction(()=>document.querySelector('button[form="master-edit-3130"]').textContent.includes('Gespeichert'));
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
    await form.locator('summary').click();
    await form.locator('textarea').fill('Noch nicht gespeichert');
    await page.locator('input[form="master-edit-3130"][name="sort_order"]').fill('10');
    await page.locator('[data-sort-key="sort_order"]').focus();
    await page.keyboard.press('Enter');
    assert.deepEqual(await names(),['Haus & Garten','Ohne Erläuterung']);
    assert.equal(await form.locator('textarea').inputValue(),'Noch nicht gespeichert');
    assert.equal(await form.locator('details').getAttribute('open'),'');
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
    await page.unroute('**/stammdaten.php');
    await page.route('**/stammdaten.php',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,action:'rename'})}));
    await button.click();
    await page.waitForFunction(()=>document.querySelector('button[form="master-edit-3130"]').textContent.includes('Gespeichert'));
    await sort.fill('27');assert.equal(await button.textContent(),'Speichern');
});
