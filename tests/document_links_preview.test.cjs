const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {JSDOM}=require('jsdom');
function fixture(small=false) {
 const dom=new JSDOM(`<div data-vorgang-workspace><a data-case-preview data-document-id="1" data-edit-url="bearbeiten.php?dokid=1&return=vorgaenge.php%3Fid%3D4" href="archiv_pdf.php?dokid=1">One</a><a data-case-preview data-document-id="2" data-edit-url="bearbeiten.php?dokid=2" href="archiv_pdf.php?dokid=2">Two</a><form id="vorgang-detach-form"><input type="hidden" name="document_id" id="vorgang-detach-document"><button id="vorgang-detach" type="submit" disabled>Entfernen</button></form><div id="vorgang-preview-actions" hidden><a id="vorgang-preview-open"></a><a id="vorgang-preview-edit"></a></div><p id="vorgang-preview-empty">Auswählen</p><div id="vorgang-preview-pdf"></div></div>`,{url:'http://localhost/vorgaenge.php?id=4',runScripts:'outside-only'});
 const w=dom.window,d=w.document;let resize;
 const media={matches:small,addEventListener:(type,fn)=>{resize=fn;}};
 w.matchMedia=()=>media;
 let submits=0;d.querySelector('form').addEventListener('submit',e=>{e.preventDefault();submits++;});
 w.eval(fs.readFileSync(path.join(__dirname,'../web/vorgang_vorschau.js'),'utf8'));
 return {w,d,media,resize:()=>resize(),links:d.querySelectorAll('[data-case-preview]'),submits:()=>submits};
}
test('case document selection updates PDF and actions without leaving or submitting',()=>{
 const f=fixture(),{d,w,links}=f;
 assert.equal(d.querySelector('iframe'),null);
 assert.equal(d.getElementById('vorgang-detach').disabled,true);
 links[0].click();const frame=d.querySelector('iframe');
 assert.equal(frame.getAttribute('src'),'archiv_pdf.php?dokid=1');
 assert.match(frame.title,/Dokument 1/);
 assert.equal(d.getElementById('vorgang-preview-actions').hidden,false);
 assert.equal(d.getElementById('vorgang-preview-empty').hidden,true);
 assert.equal(links[0].getAttribute('aria-current'),'true');
 assert.match(d.getElementById('vorgang-preview-edit').href,/return=vorgaenge/);
 links[0].click();assert.equal(d.querySelector('iframe'),frame);
 links[1].click();assert.equal(d.querySelector('iframe').getAttribute('src'),'archiv_pdf.php?dokid=2');
 assert.equal(links[0].hasAttribute('aria-current'),false);
 assert.match(d.getElementById('vorgang-preview-open').href,/dokid=2/);
 assert.equal(d.getElementById('vorgang-detach').disabled,false);
 assert.equal(d.getElementById('vorgang-detach-document').value,'2');
 assert.match(d.getElementById('vorgang-detach').getAttribute('aria-label'),/Dokument 2/);
 assert.equal(f.submits(),0);assert.match(w.location.href,/vorgaenge.php/);
 w.close();
});
test('mobile keeps actions available, defers iframe and adapts to viewport changes',()=>{
 const {w,d,links,media,resize}=fixture(true);
 links[0].click();assert.equal(d.querySelector('iframe'),null);
 assert.equal(d.getElementById('vorgang-preview-actions').hidden,false);
 media.matches=false;resize();assert.equal(d.querySelector('iframe').getAttribute('src'),'archiv_pdf.php?dokid=1');
 media.matches=true;resize();assert.equal(d.querySelector('iframe'),null);
 w.close();
});

test('one header action submits the current selection and stays disabled without selection',()=>{
 const f=fixture(),{w,d,links}=f;
 const button=d.getElementById('vorgang-detach');
 button.click();assert.equal(f.submits(),0);
 links[0].click();links[1].click();button.click();
 assert.equal(f.submits(),1);
 assert.equal(new w.FormData(d.getElementById('vorgang-detach-form')).get('document_id'),'2');
 w.close();
});
