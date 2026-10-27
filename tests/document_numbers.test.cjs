const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('jsdom');
function setup(numbers=[], kinds=[{id:'1',Bezeichnung:'Kunde',Aktiv:1},{id:'2',Bezeichnung:'Vertrag',Aktiv:1}]) {
 const dom=new JSDOM(`<form data-classification-draft><input name="erkennungs_info"><div id="number-editor"><div data-number-rows></div><button data-number-add type="button"></button><input type="hidden" id="numbers_json" name="numbers_json"><input id="Nummer"><script type="application/json" id="number-kinds">${JSON.stringify(kinds)}</script></div></form>`,{runScripts:'outside-only',url:'http://eablage.test/verarbeiten.php?file=scan.pdf'});
 dom.window.document.getElementById('numbers_json').value=JSON.stringify(numbers);
 dom.window.eval(fs.readFileSync(__dirname+'/../web/ki_badges.js','utf8'));
 dom.window.eval(fs.readFileSync(__dirname+'/../web/nummern.js','utf8'));
 return dom.window;
}
test('AI fills empty type and manual edits keep the proposal available',()=>{
 const w=setup();w.eablageNumbers.suggest([{kind_id:'1',value:'0001'}],'test',false);
 const input=w.document.querySelector('[data-number-rows] input');
 assert.equal(input.value,'0001');assert.equal(w.document.querySelector('.ea-source-badge').textContent,'KI');
 input.value='0002';input.dispatchEvent(new w.Event('input',{bubbles:true}));
 const button=w.document.querySelector('.ea-ollama-apply');assert.equal(button.hidden,false);button.click();
 assert.equal(input.value,'0001');assert.deepEqual(JSON.parse(w.document.getElementById('numbers_json').value),[{kind_id:'1',value:'0001'}]);
});
test('AI never overwrites an existing number',()=>{
 const w=setup([{kind_id:'1',value:'manual'}]);w.eablageNumbers.suggest([{kind_id:'1',value:'AI'},{kind_id:'2',value:'V2'}],'test',true);
 assert.equal(w.eablageNumbers.values()[0].value,'manual');assert.equal(w.eablageNumbers.values()[1].value,'V2');
});
test('background result preserves a deliberately cleared value',()=>{
 const w=setup([{kind_id:'1',value:'manual'}]);const input=w.document.querySelector('[data-number-rows] input');
 input.value='';input.dispatchEvent(new w.Event('input',{bubbles:true}));
 w.eablageNumbers.suggest([{kind_id:'1',value:'AI'}],'test',true);assert.equal(input.value,'');
 assert.equal(w.document.querySelector('.ea-ollama-apply').hidden,false);
});
test('draft restoration rebuilds typed rows',()=>{
 const w=setup();const hidden=w.document.getElementById('numbers_json');hidden.value=JSON.stringify([{kind_id:'2',value:'V/123'}]);hidden.dispatchEvent(new w.Event('change'));
 assert.equal(w.document.querySelector('select').value,'2');assert.equal(w.document.querySelector('[data-number-rows] input').value,'V/123');
});
test('AutoKat uses colored rule IDs with version, rank and draft link',()=>{
 for (const [type,style] of [['K','classification'],['E','supplement']]) {
  const w=setup([{kind_id:'1',value:'0001',source:{type,regel_id:'R0042',version:3,rank:20,label:'Testregel'}}]);
  const badge=w.document.querySelector('.ea-source-badge');
  assert.equal(badge.textContent,'R0042');assert.equal(badge.classList.contains('ea-source-badge--'+style),true);
  assert.match(badge.title,/R0042\/v3/);assert.match(badge.title,/Rang 20/);assert.match(badge.title,/Testregel/);
  assert.equal(badge.hasAttribute('data-draft-link'),true);assert.match(badge.getAttribute('href'),/regel=R0042/);
  assert.equal(new URL(badge.href).searchParams.get('return'),'verarbeiten.php?file=scan.pdf');
 }
});
test('AI confirms an unchanged rule value and restores that indication after editing',()=>{
 const w=setup([{kind_id:'1',value:'0001',source:{type:'K',regel_id:'R0042'}}]);
 w.eablageNumbers.suggest([{kind_id:'1',value:'0001'}],'test',true);
 const confirmed=w.document.querySelector('.ea-ollama-confirmed'), input=w.document.querySelector('[data-number-rows] input');
 assert.equal(confirmed.hidden,false);assert.equal(w.document.querySelector('.ea-source-badge').textContent,'R0042');
 assert.equal(w.document.querySelector('.ea-ollama-apply').hidden,true);
 input.value='0002';input.dispatchEvent(new w.Event('input',{bubbles:true}));
 assert.equal(confirmed.hidden,true);assert.equal(w.document.querySelector('.ea-ollama-apply').hidden,false);
 input.value='0001';input.dispatchEvent(new w.Event('input',{bubbles:true}));assert.equal(confirmed.hidden,false);
});
test('adopting an alternative replaces the rule link with an AI badge',()=>{
 const w=setup([{kind_id:'1',value:'0001',source:{type:'E',regel_id:'R0042'}}]);
 w.eablageNumbers.suggest([{kind_id:'1',value:'0002'}],'test',false);w.document.querySelector('.ea-ollama-apply').click();
 const badge=w.document.querySelector('.ea-source-badge');assert.equal(badge.textContent,'KI');
 assert.equal(badge.tagName,'SPAN');assert.equal(badge.hasAttribute('data-draft-link'),false);
 assert.equal(w.document.querySelector('.ea-ollama-confirmed').hidden,true);
});
test('text comparison shows linked source document and can be confirmed by AI',()=>{
 const w=setup([{kind_id:'1',value:'0001',source:{type:'textcompare',source_document_id:42,similarity:98}}]);
 const badge=w.document.querySelector('.ea-source-badge');
 assert.equal(badge.textContent,'DokID 42');assert.equal(badge.classList.contains('ea-source-badge--date'),true);
 assert.match(badge.title,/98 % Übereinstimmung/);assert.equal(badge.hasAttribute('data-draft-link'),true);
 assert.equal(new URL(badge.href).pathname,'/bearbeiten.php');
 assert.equal(new URL(badge.href).searchParams.get('dokid'),'42');
 assert.equal(new URL(badge.href).searchParams.get('return'),'verarbeiten.php?file=scan.pdf');
 w.eablageNumbers.suggest([{kind_id:'1',value:'0001',evidence:'Kundennummer: 0001'}],'test',true);
 assert.equal(w.document.querySelector('.ea-ollama-confirmed').hidden,false);
 assert.equal(badge.textContent,'DokID 42');
 const input=w.document.querySelector('[data-number-rows] input');input.value='manual';input.dispatchEvent(new w.Event('input',{bubbles:true}));
 assert.equal(w.document.querySelector('a.ea-source-badge'),null);
});
test('invalid comparison document IDs do not produce links',()=>{
 const w=setup([{kind_id:'1',value:'0001',source:{type:'textcompare',source_document_id:'javascript:bad'}}]);
 assert.equal(w.document.querySelector('a.ea-source-badge'),null);
});
test('recognition and draft order follow kind priority while preserving sources',()=>{
 const kinds=[{id:'2',Bezeichnung:'Kundennummer',Aktiv:1},{id:'4',Bezeichnung:'Rechnungsnummer',Aktiv:1},{id:'13',Bezeichnung:'Referenznummer',Aktiv:1}];
 const invoice={kind_id:'4',value:'700460585',source:{type:'K',regel_id:'R0042'}};
 const w=setup([invoice,{kind_id:'13',value:'2600852765'},{kind_id:'2',value:'46648639'}],kinds);
 assert.deepEqual(JSON.parse(JSON.stringify(w.eablageNumbers.values())).map(n=>n.kind_id),['2','4','13']);
 assert.equal(w.document.getElementById('Nummer').value,'46648639');
 assert.equal(w.document.querySelector('.ea-source-badge--classification').textContent,'R0042');
 const hidden=w.document.getElementById('numbers_json');
 hidden.value=JSON.stringify([invoice,{kind_id:'2',value:'46648639'}]);hidden.dispatchEvent(new w.Event('change'));
 assert.equal(w.eablageNumbers.values()[0].kind_id,'2');
});
test('late AI additions and removal update the compatibility number by kind priority',()=>{
 const kinds=[{id:'2',Bezeichnung:'Kundennummer',Aktiv:1},{id:'4',Bezeichnung:'Rechnungsnummer',Aktiv:1},{id:'13',Bezeichnung:'Referenznummer',Aktiv:1}];
 const w=setup([{kind_id:'4',value:'700460585'}],kinds);
 w.eablageNumbers.suggest([{kind_id:'13',value:'2600852765'},{kind_id:'2',value:'46648639'}],'test',true);
 assert.deepEqual(JSON.parse(w.document.getElementById('numbers_json').value).map(n=>n.kind_id),['2','4','13']);
 assert.equal(w.document.getElementById('Nummer').value,'46648639');
 w.document.querySelector('.ea-number-remove').click();
 assert.equal(w.document.getElementById('Nummer').value,'700460585');
});
