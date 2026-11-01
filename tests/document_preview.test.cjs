const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {execFileSync}=require('node:child_process');
const {JSDOM}=require('jsdom');
const root=path.join(__dirname,'../web');
for(const url of ['pdf.php?file='+encodeURIComponent('Sehr langer Originalname '+ 'a'.repeat(200) +'.pdf'), 'archiv_pdf.php?dokid=10001']) {
 test('shared preview safely renders and switches views: '+url.split('?')[0],()=>{
  const text='Zeile 1\n<script>alert("unsafe")</script> & Dokumenttext';
  const html=execFileSync(process.env.PHP_BIN||'php',['-r','require $argv[1]; eablage_dokumentvorschau($argv[2],$argv[3]);',path.join(root,'dokumentvorschau.php'),url,text],{encoding:'utf8'});
  const dom=new JSDOM(html,{runScripts:'dangerously',url:'http://localhost/'}),w=dom.window,d=w.document;
  const frame=d.querySelector('iframe');
  assert.equal(d.querySelector('.panel-heading > span').textContent,'PDF-Vorschau');
  assert.equal(frame.getAttribute('src'),url);
  assert.equal(d.querySelector('.ea-mobile-pdf-overlay a').getAttribute('href'),url);
  assert.equal(d.querySelector('pre').textContent,text);
  assert.equal(d.querySelectorAll('script:not([src])').length,0);
  w.eval(fs.readFileSync(path.join(root,'dokumentvorschau.js'),'utf8'));
  d.getElementById('viewTextButton').click();
  assert.equal(d.getElementById('pdfDocumentView').hidden,true);
  assert.equal(d.getElementById('textDocumentView').hidden,false);
  assert.equal(d.getElementById('viewTextButton').getAttribute('aria-pressed'),'true');
  d.getElementById('viewPdfButton').click();
  assert.equal(d.getElementById('textDocumentView').hidden,true);
  assert.equal(d.getElementById('viewPdfButton').classList.contains('active'),true);
  assert.equal(d.querySelector('iframe'),frame);
  w.close();
 });
}
