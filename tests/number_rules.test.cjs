const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const {JSDOM}=require('jsdom');
function setup(definitions=[]) {
 const dom=new JSDOM('<form><div id="number-rule-editor"><div data-rule-number-rows></div><button type="button" data-rule-number-add>Add</button><input type="hidden" id="number_rules_json"><script type="application/json" id="rule-number-kinds">[{"id":"1","Bezeichnung":"Kundennummer"}]</script></div></form>',{runScripts:'outside-only'});
 dom.window.document.getElementById('number_rules_json').value=JSON.stringify(definitions);
 dom.window.eval(fs.readFileSync(__dirname+'/../web/nummern_regeln.js','utf8'));
 return dom.window;
}
test('new number rules expose and serialize optional maximum length',()=>{
 const w=setup(),d=w.document;
 d.querySelector('[data-rule-number-add]').click();
 const length=d.querySelector('[data-key=MaxLaenge]');
 assert.equal(length.type,'number');assert.equal(length.value,'0');assert.equal(length.min,'0');
 length.value='11';length.dispatchEvent(new w.Event('input'));
 assert.equal(JSON.parse(d.getElementById('number_rules_json').value)[0].MaxLaenge,11);
 length.value='';length.dispatchEvent(new w.Event('input'));
 assert.equal(JSON.parse(d.getElementById('number_rules_json').value)[0].MaxLaenge,0);
});
test('existing new and legacy lengths survive editing another setting',()=>{
 for (const mode of ['zeile','legacy']) {
  const w=setup([{NummernartID:'1',Startanker:'Kundennummer:',Endmodus:mode,MaxLaenge:11,Praefix:'Alt',Endanker:'',Festwert:''}]);
  const d=w.document,length=d.querySelector('[data-key=MaxLaenge]');
  assert.equal(length.type,'number');assert.equal(length.value,'11');
  const anchor=d.querySelector('[data-key=Startanker]');anchor.value='Kunde:';anchor.dispatchEvent(new w.Event('input'));
  const saved=JSON.parse(d.getElementById('number_rules_json').value)[0];
  assert.equal(saved.MaxLaenge,11);assert.equal(saved.Endmodus,mode);
  if(mode==='legacy') assert.equal(saved.Praefix,'Alt');
 }
});
