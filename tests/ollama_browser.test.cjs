// Run with node --test; uses Playwright for actual DOM and fetch behaviour.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const path = require('node:path');
const fs = require('node:fs');
const css = fs.readFileSync(path.join(__dirname, '../web/app.css'), 'utf8');
const badgeScript = fs.readFileSync(path.join(__dirname, '../web/ki_badges.js'), 'utf8');
const script = fs.readFileSync(path.join(__dirname, '../web/ollama.js'), 'utf8');
const proposal = {model:'qwen3.5:9b', proposal:{date:'2026-10-11',partner:'KI Partner',subject:'<img src=x onerror=alert(1)>',category:'001',document_type:'002',number:'A123',tags:'Wärmepumpe, Förderantrag',date_evidence:'Datum: 11.10.2026',number_evidence:'A123'},labels:{category:'Versicherung',document_type:'Rechnung'},warnings:[]};
const classificationSource = fs.readFileSync(path.join(__dirname, '../web/verarbeiten.php'), 'utf8');
const recognitionNotesMarkup = classificationSource.match(/<section id="ollama-recognition-details"[\s\S]*?<\/section>/)[0];
const diagnosticMarkup = classificationSource.match(/<section class="ea-ollama-panel ea-ollama-diagnostic"[\s\S]*?<\/section>/)[0];
const statusMarkup = classificationSource.match(/<section class="ea-ollama-panel" id="ollama-panel"[\s\S]*?<\/section>/)[0];
const html = `<details id="classification-more-actions"><summary>⋮</summary><button type="button" id="ollama-suggest" data-text-sha256="text-hash">KI-Vorschlag erstellen</button><button type="button" id="ollama-diagnostic-open">KI-Anfrage anzeigen</button></details><details id="classification-recognition-details"><summary>Erkennungsdetails</summary>${recognitionNotesMarkup}${diagnosticMarkup}</details>${statusMarkup}
<form id="classification-document-form"><input name="aktion_token" value="token"><input name="original_datei" value="test.pdf"><input name="source_sha256" value="${'a'.repeat(64)}"><input name="inbox_job_id" value="1"><input name="erkennungs_info" value="Manuell">
<label for="Partner">Partner <span class="ea-source-badge">R0001</span></label><input name="Partner" id="Partner" value="Manueller Partner">
<label for="Datum">Datum</label><input id="Datum" name="Datum" type="date" value="2020-01-01"><label for="Betreff">Betreff</label><input name="Betreff" id="Betreff" value="Manueller Betreff"><label for="Kategorie">Kategorie</label><select name="Kategorie" id="Kategorie"><option value="009">Andere Kategorie</option><option value="0"></option><option value="001">Versicherung</option></select><label for="Doktyp">Typ</label><select name="Doktyp" id="Doktyp"><option value="009">Anderer Typ</option><option value="0"></option><option value="002">Rechnung</option></select><label for="Nummer">Nummer</label><input name="Nummer" id="Nummer" value="Manuelle Nummer"><label for="Tags">Tags</label><input name="Tags" id="Tags" value="Manuelle Tags"><select name="Kategorie2"><option value="0"></option><option value="001">Versicherung</option></select></form>`;
async function setup(t, body=proposal, status=200, diagnostics=true, backgroundHandler=null, restoredDraft=false, touch=false) {
    const browser = await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_EXECUTABLE_PATH,args:['--no-sandbox']});
    t.after(()=>browser.close());
    const page = await browser.newPage(touch ? {hasTouch:true,viewport:{width:390,height:844}} : {});
    await page.route('http://eablage.test/**', route => {
        if(route.request().url().endsWith('/ollama.php')) {
            const action = new URLSearchParams(route.request().postData()).get('action');
            if (action === 'background' && backgroundHandler) return backgroundHandler(route);
            return route.fulfill({status,contentType:'application/json',body:JSON.stringify(body)});
        }
        let markup = diagnostics ? html : html.replace(diagnosticMarkup, '').replace(/<button type="button" id="ollama-diagnostic-open">[\s\S]*?<\/button>/, '');
        if (backgroundHandler) markup = markup.replace('id="ollama-suggest"', 'id="ollama-suggest" data-background="true"');
        return route.fulfill({contentType:'text/html',body:markup});
    });
    await page.goto('http://eablage.test/verarbeiten.php');
    await page.addStyleTag({content:css});
    if (restoredDraft) await page.evaluate(()=>{ const form=document.querySelector('#classification-document-form'); form.dataset.classificationDraftRestored='true'; form.elements.namedItem('Partner').value=''; });
    await page.addScriptTag({content:badgeScript});
    await page.addScriptTag({content:script});
    return page;
}
test('one-click adoption without dialogs, safe text and recognition provenance', async t=>{
    const page = await setup(t);
    assert.equal(await page.locator('#ollama-panel').isVisible(),false);
    await page.click('#classification-more-actions summary');
    await page.click('#ollama-suggest');
    await page.waitForSelector('.ea-ollama-field', {state:'attached'});
    assert.equal(await page.locator('#classification-more-actions').getAttribute('open'),null);
    assert.equal(await page.locator('#ollama-panel').isVisible(),false);
    assert.equal(await page.locator('#ollama-status').textContent(),'');
    assert.equal(await page.inputValue('[name=Partner]'),'Manueller Partner');
    assert.equal(await page.inputValue('[name=erkennungs_info]'),'Manuell');
    assert.equal(await page.locator('.ea-ollama-field img').count(),0);
    const partner = page.locator('.ea-ollama-field[data-field=Partner] button');
    let dialogs = 0;
    page.on('dialog',dialog=>{dialogs++; dialog.dismiss();});
    await partner.click();
    assert.equal(await page.inputValue('[name=Partner]'),'KI Partner');
    assert.match(await page.inputValue('[name=erkennungs_info]'),/KI \(Ollama: qwen3.5:9b\)/);
    assert.equal(await page.locator('label[for=Partner] .ea-source-badge:not(.ea-ollama-confirmed)').innerText(),'KI');
    await page.locator('.ea-ollama-field[data-field=Kategorie] button').click();
    assert.equal(await page.inputValue('[name=Kategorie]'),'001');
    assert.equal(dialogs,0);
    assert.equal(await page.locator('.ea-ollama-field[data-field=Partner]').isVisible(),false);
    assert.equal(await page.locator('.ea-ollama-field[data-field=Kategorie]').isVisible(),false);
    assert.equal(await page.locator('#ollama-close').count(),0);
    assert.doesNotMatch(classificationSource, /Vorschläge ausblenden|id="ollama-close"/);
});
test('failure leaves manual fields and provenance intact and permits retry',async t=>{
    const page = await setup(t,{error:'Lokale KI nicht erreichbar'},400);
    await page.click('#classification-more-actions summary');
    await page.click('#ollama-suggest');
    await page.waitForFunction(()=>document.querySelector('#ollama-status').textContent.includes('konnte nicht erstellt werden'));
    assert.equal(await page.inputValue('[name=Partner]'),'Manueller Partner');
    assert.equal(await page.inputValue('[name=erkennungs_info]'),'Manuell');
    assert.equal(await page.locator('#ollama-suggest').isEnabled(),true);
    assert.doesNotMatch(await page.locator('#ollama-status').innerText(),/nicht erreichbar/);
    assert.equal(await page.locator('[data-background-error]').isVisible(),false);
    await page.locator('#ollama-status a').click();
    assert.equal(await page.locator('#classification-recognition-details').getAttribute('open'),'');
    assert.match(await page.locator('[data-background-error]').innerText(),/nicht erreichbar/);
});

test('connection test sends only saved configuration request', async t=>{
    const page = await setup(t);
    await page.setContent('<form id="settings-form"><input name="aktion_token" value="token"><input name="params[ollamaURL]" value="unsaved"></form><button id="ollama-check">Test</button><p id="ollama-check-status"></p>');
    let payload;
    await page.route('**/ollama.php', route=>{
        payload=route.request().postData();
        return route.fulfill({contentType:'application/json',body:JSON.stringify({model:'qwen3.5:9b',message:'Ollama erreichbar'})});
    });
    await page.addScriptTag({content:script});
    await page.click('#ollama-check');
    await page.waitForFunction(()=>document.querySelector('#ollama-check-status').textContent.includes('erreichbar'));
    assert.equal(payload,'action=check&aktion_token=token');
});

test('status is visible while generating and disappears on success without hiding field proposals', async t=>{
    const page = await setup(t);
    let respond;
    await page.route('**/ollama.php', route=>new Promise(resolve=>{
        respond=async()=>{await route.fulfill({contentType:'application/json',body:JSON.stringify(proposal)});resolve();};
    }));
    assert.equal(await page.locator('#ollama-panel').isVisible(),false);
    await page.click('#classification-more-actions summary');
    await page.click('#ollama-suggest');
    await page.waitForFunction(()=>document.querySelector('#ollama-status').textContent.includes('wird erstellt'));
    assert.equal(await page.locator('#ollama-panel').isVisible(),true);
    await respond();
    await page.waitForFunction(()=>document.querySelectorAll('.ea-ollama-field').length>0);
    assert.equal(await page.locator('#ollama-panel').isVisible(),false);
    assert.equal(await page.inputValue('[name=Partner]'),'Manueller Partner');
    assert.equal(await page.locator('#ollama-suggest').isEnabled(),true);
});

test('field proposals show equality, evidence and long values without overflow', async t=>{
    const page = await setup(t);
    await page.setViewportSize({width:390,height:844});
    await page.fill('[name=Partner]','KI Partner');
    await page.click('#classification-more-actions summary');
    await page.click('#ollama-suggest');
    await page.waitForSelector('.ea-ollama-field', {state:'attached'});
    const partner = page.locator('.ea-ollama-field[data-field=Partner]');
    assert.equal(await page.locator('label[for=Partner] .ea-ollama-confirmed').isVisible(),true);
    assert.equal(await partner.locator('button').isVisible(),false);
    assert.equal(await page.inputValue('[name=erkennungs_info]'),'Manuell');
    await page.fill('[name=Partner]','Anderer Partner');
    assert.equal(await partner.locator('button').isVisible(),true);
    assert.equal(await page.locator('label[for=Partner] .ea-ollama-confirmed').isVisible(),false);
    const date = page.locator('.ea-ollama-field[data-field=Datum]');
    assert.equal(await date.locator('button').textContent(),'KI: 11.10.2026 → Übernehmen');
    assert.equal(await date.locator('details').count(),0);
    await date.locator('button').click();
    await page.locator('label[for=Datum] .ea-source-badge--ai:not(.ea-ollama-confirmed)').click();
    assert.match(await page.locator('#ea-ai-evidence-tooltip').innerText(),/Datum: 11.10.2026/);
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('[name=Betreff]').evaluate(el=>el.nextElementSibling.dataset.field),'Betreff');
    assert.equal(await page.locator('.ea-ollama-field[data-field=Betreff] img').count(),0);
    assert.equal(await page.locator('.ea-ollama-field[data-field=Betreff] button').innerText(),'KI: <img src=x onerror=alert(1)> → Übernehmen');
    await partner.locator('button').evaluate(el=>{el.textContent='KI: '+ 'Sehr langer Vorschlag '.repeat(12)+' → Übernehmen';});
    assert.equal(await partner.evaluate(el=>el.scrollWidth <= el.clientWidth),true);
    assert.equal(await page.locator('#ollama-panel').isVisible(),false);
    assert.ok(await page.locator('.ea-ollama-field:visible').count()>0);
});

test('empty fields are populated without saving, with violet badges and evidence', async t=>{
    const page = await setup(t);
    for (const name of ['Datum', 'Partner', 'Betreff', 'Nummer', 'Tags']) await page.fill('[name='+name+']','');
    for (const name of ['Kategorie','Doktyp']) await page.selectOption('[name='+name+']','0');
    let requests = 0;
    page.on('request', request=>{if(request.method()==='POST') requests++;});
    await page.click('#classification-more-actions summary');
    await page.click('#ollama-suggest');
    await page.waitForFunction(()=>document.querySelector('[name=Tags]').value.includes('Wärmepumpe'));
    assert.equal(requests,1);
    assert.equal(await page.inputValue('[name=Datum]'),'2026-10-11');
    assert.equal(await page.inputValue('[name=Partner]'),'KI Partner');
    assert.equal(await page.inputValue('[name=Kategorie]'),'001');
    assert.equal(await page.inputValue('[name=Doktyp]'),'002');
    assert.equal(await page.inputValue('[name=Tags]'),'Wärmepumpe, Förderantrag');
    assert.equal(await page.locator('.ea-source-badge--ai:not(.ea-ollama-confirmed)').count(),7);
    assert.equal(await page.locator('.ea-ollama-field[data-field=Datum]').isVisible(),false);
    assert.equal(await page.locator('label[for=Datum] [data-ai-evidence]').count(),2);
    assert.equal(await page.locator('.ea-ollama-field[data-field=Nummer]').isVisible(),false);
    await page.fill('[name=Partner]','Eigene Korrektur');
    assert.equal(await page.locator('.ea-ollama-field[data-field=Partner] button').isVisible(),true);
    assert.equal(await page.inputValue('[name=Partner]'),'Eigene Korrektur');
    assert.equal(await page.inputValue('[name=Tags]'),'Wärmepumpe, Förderantrag');
});
test('inputs entered while waiting are preserved, tags use latest metadata', async t=>{
    const page = await setup(t);
    await page.fill('[name=Partner]','');
    await page.fill('[name=Tags]','');
    let respond, payload;
    await page.route('**/ollama.php', route=>new Promise(resolve=>{
        payload = new URLSearchParams(route.request().postData());
        respond=async()=>{await route.fulfill({contentType:'application/json',body:JSON.stringify(proposal)});resolve();};
    }));
    await page.click('#classification-more-actions summary');
    await page.click('#ollama-suggest');
    await page.waitForFunction(()=>document.querySelector('#ollama-status').textContent.includes('wird erstellt'));
    await page.fill('[name=Partner]','Inzwischen eingegeben');
    await page.fill('[name=Betreff]','Förderantrag');
    await respond();
    await page.waitForFunction(()=>document.querySelector('[name=Tags]').value !== '');
    assert.equal(await page.inputValue('[name=Partner]'),'Inzwischen eingegeben');
    assert.equal(await page.inputValue('[name=Tags]'),'Wärmepumpe');
    assert.equal(JSON.parse(payload.get('current_fields')).partner,'');
    assert.equal(await page.locator('label[for=Partner] .ea-source-badge--ai:not(.ea-ollama-confirmed)').count(),0);
});

function capturedRequest() {
    const outgoing={model:'qwen3.5:9b',think:false,stream:false,format:{type:'object'},options:{num_ctx:16384,num_predict:1500},messages:[{role:'system',content:'Feste Vorgaben'},{role:'user',content:JSON.stringify({document_text:'Heckenschere\n<img src=x onerror=alert(1)>',classification_guidance:'Ausschlüsse beachten',categories:{'Haus und Garten':'Gartengeräte'},document_types:['Rechnung'],current_fields:{partner:'Manueller Partner',category:'Haus und Garten',document_type:'Rechnung'}})}]};
    return {url:'http://192.168.1.2:11434/api/chat',request:outgoing,body:JSON.stringify(outgoing),truncated:false,response_received:true};
}
test('diagnostic shows captured request without new fetch or field changes and supports copy fallback', async t=>{
    const diagnostic=capturedRequest();
    const page=await setup(t,{...proposal,diagnostic});
    let posts=0;page.on('request',r=>{if(r.method()==='POST')posts++;});
    await page.click('#classification-more-actions summary');
    await page.click('#ollama-diagnostic-open');
    assert.equal(posts,0);
    assert.match(await page.locator('#ollama-diagnostic-status').innerText(),/Noch keine Anfrage/);
    await page.click('#ollama-diagnostic-close');
    await page.click('#classification-more-actions summary');
    await page.click('#ollama-suggest');
    await page.waitForSelector('.ea-ollama-field', {state:'attached'});
    await page.fill('[name=Partner]','Inzwischen geändert');
    await page.click('#classification-more-actions summary');
    await page.click('#ollama-diagnostic-open');
    assert.equal(posts,1);
    assert.equal(await page.inputValue('[name=Partner]'),'Inzwischen geändert');
    assert.match(await page.locator('#ollama-diagnostic-content').textContent(),/Manueller Partner/);
    assert.match(await page.locator('#ollama-diagnostic-content').textContent(),/Gartengeräte/);
    assert.match(await page.locator('#ollama-diagnostic-content').textContent(),/Haus und Garten/);
    assert.doesNotMatch(await page.locator('#ollama-diagnostic-content').textContent(),/current_category_labels/);
    assert.equal(await page.locator('#ollama-diagnostic-content img').count(),0);
    assert.equal(await page.inputValue('#ollama-diagnostic-json'),diagnostic.body);
    await page.locator('#ollama-diagnostic-json-details > summary').click();
    await page.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:()=>Promise.reject(new Error('Unavailable'))}}));
    await page.click('#ollama-diagnostic-copy');
    await page.waitForFunction(()=>document.querySelector('#ollama-diagnostic-copy').textContent.includes('Strg+C'));
    assert.equal(await page.locator('#ollama-diagnostic-json').evaluate(el=>el.value.slice(el.selectionStart,el.selectionEnd)),diagnostic.body);
    await page.click('#ollama-diagnostic-close');
    assert.equal(await page.inputValue('[name=Partner]'),'Inzwischen geändert');
});
test('diagnostic remains available after proposal error', async t=>{
    const diagnostic=capturedRequest();
    const page=await setup(t,{error:'Modellantwort ungültig',diagnostic},400);
    await page.click('#classification-more-actions summary');await page.click('#ollama-suggest');
    await page.waitForFunction(()=>document.querySelector('#ollama-status').textContent.includes('konnte nicht erstellt werden'));
    await page.click('#classification-more-actions summary');await page.click('#ollama-diagnostic-open');
    assert.equal(await page.inputValue('#ollama-diagnostic-json'),diagnostic.body);
    assert.equal(await page.inputValue('[name=Partner]'),'Manueller Partner');
});

test('classification still works when diagnostic menu and panel are disabled', async t=>{
    const page = await setup(t, {...proposal,diagnostic:{request:{model:'qwen3.5:9b'}}}, 200, false);
    assert.equal(await page.locator('#ollama-diagnostic-open').count(),0);
    assert.equal(await page.locator('#ollama-diagnostic-panel').count(),0);
    const errors=[];
    page.on('pageerror', error=>errors.push(error.message));
    await page.click('#classification-more-actions summary');
    await page.click('#ollama-suggest');
    const partner=page.locator('.ea-ollama-field[data-field=Partner] button');
    await partner.waitFor();
    await partner.click();
    assert.equal(await page.inputValue('[name=Partner]'),'KI Partner');
    assert.deepEqual(errors,[]);
});

test('cached background proposal fills untouched empty fields, preserves populated ones and makes no generation request', async t=>{
    let actions=[];
    const page=await setup(t,proposal,200,false,async route=>{
        actions.push(new URLSearchParams(route.request().postData()).get('action'));
        return route.fulfill({contentType:'application/json',body:JSON.stringify({status:'bereit',suggestion:proposal})});
    });
    await page.waitForSelector('.ea-ollama-field', {state:'attached'});
    assert.equal(await page.inputValue('[name=Partner]'),'Manueller Partner');
    assert.deepEqual(actions,['background']);
    assert.equal(await page.locator('#ollama-panel').isVisible(),false);
    assert.equal(await page.locator('#ollama-status').textContent(),'');
});
test('late background proposal does not refill an edited then emptied field', async t=>{
    let release; const gate=new Promise(resolve=>release=resolve);
    const page=await setup(t,proposal,200,false,async route=>{
        await gate;
        return route.fulfill({contentType:'application/json',body:JSON.stringify({status:'bereit',suggestion:proposal})});
    });
    await page.fill('[name=Partner]','Meine Änderung');
    await page.fill('[name=Partner]','');
    // Untouched empty field may still be filled; no input/change event is emitted here.
    await page.evaluate(()=>document.querySelector('[name=Betreff]').value='');
    release();
    await page.waitForSelector('.ea-ollama-field[data-field=Partner]');
    assert.equal(await page.inputValue('[name=Partner]'),'');
    assert.equal(await page.inputValue('[name=Betreff]'),proposal.proposal.subject);
    await page.locator('.ea-ollama-field[data-field=Partner] button').click();
    assert.equal(await page.inputValue('[name=Partner]'),'KI Partner');
});
test('background success removes pending status and retains proposals beside edited fields', async t=>{
    let release, started; const gate=new Promise(resolve=>release=resolve); const second=new Promise(resolve=>started=resolve); let calls=0;
    const page=await setup(t,proposal,200,false,async route=>{
        calls++;
        if(calls===1) return route.fulfill({contentType:'application/json',body:JSON.stringify({status:'wartend',progress:{message:'KI-Vorschlag wird im Hintergrund vorbereitet.'}})});
        started();
        await gate;
        return route.fulfill({contentType:'application/json',body:JSON.stringify({status:'bereit',suggestion:proposal})});
    });
    await page.waitForFunction(()=>document.querySelector('#ollama-status').textContent.includes('KI wartet'));
    await page.fill('[name=Partner]','');
    await second;
    await page.fill('[name=Partner]','Eigene Korrektur');
    release();
    await page.waitForTimeout(100);
    assert.equal(await page.locator('#ollama-panel').isVisible(),false);
    assert.equal(await page.inputValue('[name=Partner]'),'Eigene Korrektur');
    assert.equal(await page.locator('.ea-ollama-field[data-field=Partner] button').isVisible(),true);
});
test('manual request supersedes an outstanding background result', async t=>{
    let release; const gate=new Promise(resolve=>release=resolve);
    const page=await setup(t,proposal,200,false,async route=>{
        await gate;
        return route.fulfill({contentType:'application/json',body:JSON.stringify({status:'bereit',suggestion:{...proposal,model:'old-model'}})});
    });
    await page.click('#classification-more-actions summary');
    await page.click('#ollama-suggest');
    await page.waitForSelector('.ea-ollama-field', {state:'attached'});
    release();
    await page.waitForTimeout(100);
    assert.equal(await page.locator('#ollama-panel').isVisible(),false);
    assert.match(await page.locator('#ollama-recognition-notes').textContent(),/qwen3.5:9b/);
    assert.doesNotMatch(await page.locator('#ollama-recognition-notes').textContent(),/old-model/);
});

test('background proposal preserves empty values restored from a classification excursion', async t=>{
    const page=await setup(t,proposal,200,false,async route=>route.fulfill({contentType:'application/json',body:JSON.stringify({status:'bereit',suggestion:proposal})}),true);
    await page.waitForSelector('.ea-ollama-field[data-field=Partner]');
    assert.equal(await page.inputValue('[name=Partner]'),'');
    await page.locator('.ea-ollama-field[data-field=Partner] button').click();
    assert.equal(await page.inputValue('[name=Partner]'),'KI Partner');
});

test('diagnostic formats response times and counts without changing request JSON', async t=>{
    const outgoing={model:'qwen3.5:9b',think:true,messages:[{role:'user',content:'{}'}]};
    const body=JSON.stringify(outgoing);
    const page=await setup(t,{...proposal,diagnostic:{request:outgoing,body,response_received:true,done_reason:'stop',metrics:{total_duration:25000000000,load_duration:0,prompt_eval_duration:3000000000,eval_duration:22000000000,prompt_eval_count:1800,eval_count:1100}}});
    await page.click('#classification-more-actions summary'); await page.click('#ollama-suggest');
    await page.waitForSelector('.ea-ollama-field', {state:'attached'});
    await page.click('#classification-more-actions summary'); await page.click('#ollama-diagnostic-open');
    const rows=await page.locator('#ollama-diagnostic-content table tr').allTextContents();
    assert.ok(rows.some(r=>r.includes('Gesamtzeit') && r.includes('25,000 s')));
    assert.ok(rows.some(r=>r.includes('Modell laden') && r.includes('0,000 s')));
    assert.ok(rows.some(r=>r.includes('Eingabetokens') && r.includes('1.800')));
    assert.ok(rows.some(r=>r.includes('Generierungsgeschwindigkeit') && r.includes('50,0 Tokens/s')));
    assert.ok(rows.some(r=>r.includes('aus dem Cache') && r.includes('—')));
    assert.equal(await page.inputValue('#ollama-diagnostic-json'),body);
});
test('diagnostic preserves zero counts and safely handles missing and zero durations after error', async t=>{
    const outgoing={model:'qwen3.5:9b',messages:[]};
    const page=await setup(t,{error:'Unvollständige Antwort',diagnostic:{request:outgoing,response_received:true,done_reason:'length',metrics:{eval_count:0,eval_duration:0}}},400);
    await page.click('#classification-more-actions summary'); await page.click('#ollama-suggest');
    await page.waitForFunction(()=>document.querySelector('#ollama-status').textContent.includes('konnte nicht erstellt werden'));
    await page.click('#classification-more-actions summary'); await page.click('#ollama-diagnostic-open');
    const rows=await page.locator('#ollama-diagnostic-content table tr').allTextContents();
    assert.ok(rows.some(r=>r==='Erzeugte Tokens0'));
    assert.ok(rows.some(r=>r==='Generierungsgeschwindigkeit—'));
    assert.ok(rows.some(r=>r==='Abschlusslength'));
    assert.doesNotMatch(await page.locator('#ollama-diagnostic-content').innerText(),/NaN|Infinity/);
});

test('classification shows worker pause and expandable failure details without changing fields',async t=>{
    const page=await setup(t,proposal,200,false,async route=>route.fulfill({contentType:'application/json',body:JSON.stringify({status:'fehler',error:'Zeitlimit überschritten',progress:{state:'fehler',message:'KI fehlgeschlagen. KI pausiert – nächster Versuch in 3:20 Minuten.',error:'Zeitlimit überschritten',last_attempt:1700000000},worker:{enabled:true,available:true,fresh:true,state:'pausiert'}})}));
    await page.waitForFunction(()=>document.querySelector('#ollama-status').textContent.includes('KI fehlgeschlagen'));
    assert.doesNotMatch(await page.locator('#ollama-status').innerText(),/3:20|Zeitlimit/);
    await page.locator('#ollama-status a').click();
    assert.match(await page.locator('#ollama-recognition-notes').innerText(),/3:20/);
    assert.match(await page.locator('[data-background-error]').innerText(),/Zeitlimit überschritten/);
    assert.match(await page.locator('[data-background-error]').innerText(),/Letzter Versuch/);
    assert.equal(await page.inputValue('[name=Partner]'),'Manueller Partner');
    assert.equal(await page.locator('.ea-ollama-field').count(),0);
});
test('classification distinguishes an unavailable worker from an actively running inference',async t=>{
    const page=await setup(t,proposal,200,false,async route=>route.fulfill({contentType:'application/json',body:JSON.stringify({status:'wartend',progress:{state:'wartend',message:'KI wartet – 2 Aufträge davor.'},worker:{enabled:true,available:true,fresh:false,message:'Keine aktuelle Worker-Rückmeldung.'}})}));
    await page.waitForFunction(()=>document.querySelector('#ollama-status').textContent.includes('KI-Status derzeit nicht verfügbar'));
    await page.locator('#ollama-status a').click();
    assert.match(await page.locator('#ollama-recognition-notes').innerText(),/2 Aufträge davor|Keine aktuelle Worker-Rückmeldung/);
    assert.equal(await page.inputValue('[name=Partner]'),'Manueller Partner');
});
test('background polling resumes after a failed job and displays its later result',async t=>{
    let calls=0;
    const page=await setup(t,proposal,200,false,async route=>{
        calls++;
        return route.fulfill({contentType:'application/json',body:JSON.stringify(calls===1
            ? {status:'fehler',error:'Zeitlimit überschritten',progress:{message:'KI fehlgeschlagen.'},worker:{enabled:true,available:true,fresh:true}}
            : {status:'bereit',suggestion:proposal})});
    });
    await page.waitForFunction(()=>document.querySelector('#ollama-status').textContent.includes('fehlgeschlagen'));
    await page.waitForSelector('.ea-ollama-field',{timeout:10000});
    assert.ok(calls>=2);
    assert.equal(await page.locator('#ollama-panel').isVisible(),false);
    assert.match(await page.locator('#ollama-recognition-notes').textContent(),/qwen3.5:9b/);
    assert.equal(await page.locator('[data-background-error]').count(),0);
    assert.equal(await page.inputValue('[name=Partner]'),'Manueller Partner');
});

test('diagnostic shows the effective text window and actual selected character count',async t=>{
    const request={model:'qwen3.5:9b',messages:[{role:'user',content:JSON.stringify({document_text:'BEGIN'})}]};
    const page=await setup(t,{...proposal,diagnostic:{request,response_received:true,truncated:true,text_selection:{head:9000,tail:0,limit:9000,original_characters:20000,selected_characters:9000,sent_characters:9023}}});
    await page.click('#classification-more-actions summary');await page.click('#ollama-suggest');
    await page.waitForSelector('.ea-ollama-field', {state:'attached'});
    await page.click('#classification-more-actions summary');await page.click('#ollama-diagnostic-open');
    const section=page.locator('#ollama-diagnostic-content details').filter({has:page.locator('summary', {hasText:'Textauswahl (Zeichen)'})});
    assert.match(await section.innerText(),/"head": 9000/);
    assert.match(await section.innerText(),/"tail": 0/);
    assert.match(await section.innerText(),/"original_characters": 20000/);
    assert.match(await section.innerText(),/"selected_characters": 9000/);
});


test('fallback warning and both attempt diagnostics are displayed without changing filled fields', async t=>{
    const diagnostic=capturedRequest();
    diagnostic.request.think=false;
    diagnostic.body=JSON.stringify(diagnostic.request);
    diagnostic.attempts=[{thinking:true,done_reason:'length',abort_reason:'length'}, {thinking:false,done_reason:'stop'}];
    const page=await setup(t,{...proposal,warnings:['Der Vorschlag wurde anschließend ohne Denkmodus erstellt. Bitte besonders sorgfältig prüfen.','Langes Dokument: Nur die ersten 9000 Zeichen wurden ausgewertet.','<img src=x onerror=alert(1)>'],diagnostic});
    await page.click('#classification-more-actions summary'); await page.click('#ollama-suggest');
    await page.waitForSelector('.ea-ollama-field', {state:'attached'});
    assert.doesNotMatch(await page.locator('#ollama-status').textContent(),/ohne Denkmodus/);
    assert.equal(await page.locator('[data-ai-warnings]').isVisible(),false);
    await page.locator('#classification-recognition-details > summary').click();
    assert.match(await page.locator('#ollama-recognition-notes').innerText(),/ohne Denkmodus/);
    assert.match(await page.locator('#ollama-recognition-notes').innerText(),/Langes Dokument/);
    assert.equal(await page.locator('#ollama-recognition-notes img').count(),0);
    assert.equal(await page.locator('[data-ai-warnings]').evaluate(el=>!!el.closest('#classification-recognition-details')),true);
    assert.equal(await page.inputValue('[name=Partner]'),'Manueller Partner');
    await page.click('#classification-more-actions summary'); await page.click('#ollama-diagnostic-open');
    assert.match(await page.locator('#ollama-diagnostic-content').textContent(),/KI-Durchläufe/);
    assert.match(await page.locator('#ollama-diagnostic-content').textContent(),/length/);
    assert.equal(JSON.parse(await page.inputValue('#ollama-diagnostic-json')).think,false);
});

test('terminal background failure stops polling and still allows a manual request', async t=>{
    let polls=0;
    const page=await setup(t,proposal,200,true,async route=>{
        polls++;
        return route.fulfill({contentType:'application/json',body:JSON.stringify({status:'fehler',retryable:false,attempt_history:{summary:'Fallback ohne Denkmodus gestartet, aber fehlgeschlagen.',lines:['Erstversuch mit Denkmodus: Tokenlimit erreicht.','Fallback ohne Denkmodus: Zeitlimit überschritten.']},error:'Beide Durchläufe gescheitert',progress:{message:'Kein weiterer automatischer Versuch.'}})});
    });
    await page.waitForFunction(()=>document.querySelector('#ollama-recognition-notes').textContent.includes('Kein weiterer'));
    await page.locator('#classification-recognition-details > summary').click();
    assert.match(await page.locator('[data-ai-history]').innerText(),/Fallback ohne Denkmodus: Zeitlimit/);
    assert.equal(await page.locator('[data-ai-history] li').count(),2);
    await page.waitForTimeout(5300);
    assert.equal(polls,1);
    await page.click('#classification-more-actions summary'); await page.click('#ollama-suggest');
    await page.waitForSelector('.ea-ollama-field', {state:'attached'});
    assert.equal(await page.inputValue('[name=Partner]'),'Manueller Partner');
});


test('cached success history and legacy missing history are visible after opening classification', async t=>{
    const suggestion={...proposal,attempt_history:{summary:'Fallback ohne Denkmodus erfolgreich.',lines:['Erstversuch mit Denkmodus: Tokenlimit erreicht.','Fallback ohne Denkmodus: erfolgreich.']}};
    const page=await setup(t,proposal,200,false,route=>route.fulfill({contentType:'application/json',body:JSON.stringify({status:'bereit',suggestion})}));
    await page.waitForSelector('[data-ai-history]',{state:'attached'});
    assert.equal(await page.locator('[data-ai-history]').isVisible(),false);
    await page.locator('#classification-recognition-details > summary').click();
    assert.match(await page.locator('[data-ai-history]').innerText(),/Fallback ohne Denkmodus erfolgreich/);
    assert.equal(await page.inputValue('[name=Partner]'),'Manueller Partner');
    const legacy=await setup(t,proposal,200,true,route=>route.fulfill({contentType:'application/json',body:JSON.stringify({status:'bereit',suggestion:proposal})}));
    await legacy.waitForSelector('[data-ai-history]',{state:'attached'});
    await legacy.locator('#classification-recognition-details > summary').click();
    assert.match(await legacy.locator('[data-ai-history]').innerText(),/Keine Durchlaufhistorie/);
    assert.equal(await legacy.locator('[data-ai-history] li').count(),0);
});

for (const automatic of [true, false]) {
    test(`${automatic ? 'automatically filled' : 'explicitly adopted'} AI values remain recoverable after edits in every field`, async t=>{
        const page = await setup(t);
        const fields = {Datum: '2026-10-11', Partner: 'KI Partner', Betreff: proposal.proposal.subject,
            Kategorie: '001', Doktyp: '002', Nummer: 'A123', Tags: 'Wärmepumpe, Förderantrag'};
        if (automatic) {
            for (const name of Object.keys(fields)) {
                if (['Kategorie', 'Doktyp'].includes(name)) await page.selectOption('[name='+name+']', '0');
                else await page.fill('[name='+name+']', '');
            }
        }
        let requests = 0;
        page.on('request', request=>{if(request.method()==='POST') requests++;});
        await page.click('#classification-more-actions summary');
        await page.click('#ollama-suggest');
        await page.waitForSelector('.ea-ollama-field', {state:'attached'});
        for (const [name, value] of Object.entries(fields)) {
            const row = page.locator('.ea-ollama-field[data-field='+name+']');
            const apply = row.locator('button');
            if (!automatic) await apply.click();
            assert.equal(await page.inputValue('[name='+name+']'), value);
            assert.equal(await apply.isVisible(), false);
            for (let edit = 0; edit < 2; edit++) {
                if (['Kategorie', 'Doktyp'].includes(name)) await page.selectOption('[name='+name+']', '009');
                else await page.fill('[name='+name+']', name === 'Datum' ? '2020-02-03' : 'Eigene Korrektur');
                assert.equal(await apply.isVisible(), true, name);
                await apply.click();
                assert.equal(await page.inputValue('[name='+name+']'), value);
                assert.equal(await apply.isVisible(), false);
                assert.equal(await page.locator('label[for='+name+'] .ea-source-badge--ai:not(.ea-ollama-confirmed)').count(), 1);
            }
        }
        assert.equal(requests, 1);
        await page.fill('[name=Partner]', 'Eigene Korrektur');
        await page.fill('[name=Partner]', 'Weitere Korrektur');
        assert.equal(await page.locator('.ea-ollama-field[data-field=Partner] button').isVisible(), true);
        assert.equal(await page.locator('#ollama-panel').isVisible(), false);
        assert.equal(await page.inputValue('[name=Partner]'), 'Weitere Korrektur');
    });
}

test('a completed response without usable values keeps concise feedback visible', async t=>{
    const page = await setup(t, {...proposal, proposal:{}});
    await page.click('#classification-more-actions summary');
    await page.click('#ollama-suggest');
    await page.waitForFunction(()=>document.querySelector('#ollama-status').textContent.includes('keine verwertbaren'));
    assert.equal(await page.locator('#ollama-panel').isVisible(),true);
    assert.equal(await page.locator('.ea-ollama-field').count(),0);
    assert.equal(await page.inputValue('[name=Partner]'),'Manueller Partner');
    assert.equal(await page.locator('#ollama-suggest').isEnabled(),true);
});


test('date confirmation is a compact label badge with touch and keyboard evidence', async t=>{
    const page=await setup(t,proposal,200,true,null,false,true);
    await page.fill('[name=Datum]','2026-10-11');
    await page.evaluate(()=>{
        const badge=document.createElement('span');badge.className='ea-source-badge ea-source-badge--classification';badge.textContent='R0042';
        document.querySelector('label[for=Datum]').append(badge);
    });
    let requests=0;page.on('request',request=>{if(request.method()==='POST')requests++;});
    await page.click('#classification-more-actions summary');await page.click('#ollama-suggest');
    const confirmed=page.locator('label[for=Datum] .ea-ollama-confirmed');
    await confirmed.waitFor({state:'visible'});
    assert.equal(await page.locator('.ea-ollama-field[data-field=Datum]').isVisible(),false);
    assert.equal(await page.locator('label[for=Datum] .ea-source-badge').first().textContent(),'R0042');
    await confirmed.tap();
    const tooltip=page.locator('#ea-ai-evidence-tooltip');
    assert.equal(await tooltip.isVisible(),true);assert.match(await tooltip.innerText(),/Datum: 11.10.2026/);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
    await page.keyboard.press('Escape');assert.equal(await tooltip.isVisible(),false);
    await confirmed.focus();await page.keyboard.press('Enter');assert.equal(await tooltip.isVisible(),true);
    await page.locator('[name=Betreff]').tap();assert.equal(await tooltip.isVisible(),false);
    assert.equal(requests,1);assert.equal(await page.inputValue('[name=Datum]'),'2026-10-11');
    await page.fill('[name=Datum]','2026-01-01');assert.equal(await confirmed.isVisible(),false);
    assert.equal(await page.locator('.ea-ollama-field[data-field=Datum] button').isVisible(),true);
});
test('recognition details use the shared AutoKat alert layout for AI information, warnings and errors', async t=>{
    const page=await setup(t,{...proposal,attempt_history:{summary:'Vorschlag erfolgreich.',lines:['Erstversuch: erfolgreich.']},warnings:['Langes Dokument: Nur ein Ausschnitt wurde ausgewertet.']});
    await page.click('#classification-more-actions summary');await page.click('#ollama-suggest');
    await page.waitForSelector('[data-ai-warnings]',{state:'attached'});
    await page.locator('#classification-recognition-details > summary').click();
    assert.equal(await page.locator('#ollama-recognition-details h4, #ollama-recognition-details h5').count(),0);
    assert.equal(await page.locator('#ollama-recognition-notes > .alert-info').count(),1);
    assert.equal(await page.locator('[data-ai-warnings].alert-warning').count(),1);
    assert.match(await page.locator('#ollama-recognition-notes > .alert-info').innerText(),/qwen3.5:9b/);
    assert.match(await page.locator('[data-ai-history]').innerText(),/Vorschlag erfolgreich/);
    await page.evaluate(()=>{
        const reference=document.createElement('div');reference.id='reference-autokat';reference.className='alert alert-info';reference.textContent='ℹ️ Keine passende AutoKat-Regel gefunden.';
        document.getElementById('classification-recognition-details').append(reference);
    });
    const styles=await page.locator('#reference-autokat, #ollama-recognition-notes > .alert-info').evaluateAll(nodes=>nodes.map(node=>{
        const css=getComputedStyle(node);return [css.padding,css.borderRadius,css.borderWidth,css.backgroundColor,css.fontSize];
    }));
    assert.deepEqual(styles[0],styles[1]);
    await page.setViewportSize({width:390,height:844});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
    const failed=await setup(t,{error:'Verbindung fehlgeschlagen'},422);
    await failed.click('#classification-more-actions summary');await failed.click('#ollama-suggest');
    await failed.waitForSelector('[data-background-error]',{state:'attached'});
    await failed.locator('#classification-recognition-details > summary').click();
    assert.equal(await failed.locator('[data-background-error].alert-warning').isVisible(),true);
    assert.match(await failed.locator('[data-background-error]').innerText(),/Verbindung fehlgeschlagen/);
});
