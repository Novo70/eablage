const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {JSDOM}=require('jsdom');
const script=fs.readFileSync(path.join(__dirname,'../web/vorgaenge.js'),'utf8');
function fixture(value='[1]') {
 const dom=new JSDOM(`<form data-document-draft><input name="aktion_token" value="token"><div data-vorgaenge-editor><input name="vorgaenge_json" data-vorgaenge-value><div data-vorgaenge-rows></div><select data-vorgaenge-select><option value="">Auswahl</option><option value="1">Heizung</option><option value="2">Steuer</option></select><button type="button" data-vorgaenge-add>Zuordnen</button><details><input data-vorgaenge-name><textarea data-vorgaenge-description></textarea><button type="button" data-vorgaenge-create>Anlegen</button></details><p data-vorgaenge-error hidden></p></div><button type="submit">Speichern</button></form>`,{url:'http://localhost/bearbeiten.php?dokid=42',runScripts:'outside-only'});
 const w=dom.window,d=w.document;d.querySelector('[data-vorgaenge-value]').value=value;
 const bound=[];w.eablageBindDraftLink=link=>bound.push(link);w.eval(script);
 return {w,d,bound,input:d.querySelector('[data-vorgaenge-value]'),click:s=>d.querySelector(s).click()};
}
test('multiple assignments are unique, removable and restored from a draft',()=>{
 const f=fixture();const {w,d,input,click}=f;
 assert.equal(d.querySelector('[data-vorgaenge-rows] a').textContent,'Heizung');
 assert.equal(f.bound.length,1);
 d.querySelector('select').value='2';click('[data-vorgaenge-add]');assert.deepEqual(JSON.parse(input.value),[1,2]);
 d.querySelector('select').value='2';click('[data-vorgaenge-add]');assert.deepEqual(JSON.parse(input.value),[1,2]);
 click('[data-vorgaenge-rows] button');assert.deepEqual(JSON.parse(input.value),[2]);
 input.value='[1,2]';input.dispatchEvent(new w.Event('change'));assert.equal(d.querySelectorAll('[data-vorgaenge-rows] a').length,2);
 input.value='[99]';input.dispatchEvent(new w.Event('change'));assert.match(d.querySelector('[data-vorgaenge-rows]').textContent,/bitte prüfen/);
 w.close();
});
test('inline creation selects the result safely and preserves previous assignments',async()=>{
 const {w,d,input,click}=fixture();
 let called;
 w.fetch=async (url,options)=>{called={url,options};return {ok:true,json:async()=>({ok:true,id:3,name:'<img src=x onerror=bad()>'})};};
 d.querySelector('[data-vorgaenge-name]').value='Neuer Vorgang';
 click('[data-vorgaenge-create]');
 assert.equal(d.querySelector('button[type=submit]').disabled,true);
 await new Promise(resolve=>setImmediate(resolve));
 assert.equal(called.url,'vorgang_anlegen.php');assert.equal(called.options.body.get('aktion_token'),'token');
 assert.deepEqual(JSON.parse(input.value),[1,3]);assert.equal(d.querySelectorAll('img').length,0);
 assert.equal(d.querySelector('button[type=submit]').disabled,false);
 w.close();
});
test('failed creation keeps the current selection and reports the error',async()=>{
 const {w,d,input,click}=fixture('[1,2]');
 w.fetch=async()=>({ok:false,json:async()=>({error:'Vorgang konnte nicht angelegt werden.'})});
 d.querySelector('[data-vorgaenge-name]').value='Neu';click('[data-vorgaenge-create]');
 await new Promise(resolve=>setImmediate(resolve));
 assert.deepEqual(JSON.parse(input.value),[1,2]);assert.equal(d.querySelector('[data-vorgaenge-error]').hidden,false);
 assert.equal(d.querySelector('[data-vorgaenge-name]').value,'Neu');
 w.close();
});
