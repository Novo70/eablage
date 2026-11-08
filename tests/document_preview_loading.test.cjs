const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {execFileSync}=require('node:child_process');
const {JSDOM}=require('jsdom');
const root=path.join(__dirname,'../web');
const markup=execFileSync(process.env.PHP_BIN||'php',['-r',"require $argv[1]; eablage_dokumentvorschau();",path.join(root,'dokumentvorschau.php')],{encoding:'utf8'});
const flush=()=>new Promise(setImmediate);
function mount() {
    const dom=new JSDOM(markup,{url:'http://localhost/explorer.php',runScripts:'outside-only'});
    const {window:w}=dom, d=w.document, calls=[];
    w.fetch=(url,options)=>new Promise((resolve,reject)=>calls.push({url,options,resolve,reject}));
    w.eval(fs.readFileSync(path.join(root,'dokumentvorschau.js'),'utf8'));
    return {w,d,calls,panel:d.querySelector('[data-document-preview]'),close:()=>w.close()};
}
test('selection loads text lazily, keeps the PDF iframe, and renders markup as plain text',async()=>{
    const f=mount(),{w,d,calls,panel}=f;
    try {
        assert.equal(d.getElementById('viewTextButton').disabled,true);
        w.eablageSelectDocumentPreview(panel,{pdfUrl:'archiv_pdf.php?dokid=1',textUrl:'dokument_text.php?dokid=1'});
        const frame=d.querySelector('iframe');
        assert.equal(calls.length,0);
        d.getElementById('viewTextButton').click();
        assert.equal(calls.length,1);
        calls[0].resolve({ok:true,json:async()=>({text:'<script>unsafe</script> Müller & Söhne'})});
        await flush();
        assert.equal(d.querySelector('pre').textContent,'<script>unsafe</script> Müller & Söhne');
        assert.equal(d.querySelector('pre script'),null);
        d.getElementById('viewPdfButton').click();
        d.getElementById('viewTextButton').click();
        assert.equal(calls.length,1);
        assert.equal(d.querySelector('iframe'),frame);
    } finally { f.close(); }
});
test('late text responses cannot replace a new selection or resurrect a cleared document',async()=>{
    const f=mount(),{w,d,calls,panel}=f;
    try {
        w.eablageSelectDocumentPreview(panel,{pdfUrl:'archiv_pdf.php?dokid=1',textUrl:'text1'});
        d.getElementById('viewTextButton').click();
        w.eablageSelectDocumentPreview(panel,{pdfUrl:'archiv_pdf.php?dokid=2',textUrl:'text2'});
        assert.equal(calls[0].options.signal.aborted,true);
        calls[1].resolve({ok:true,json:async()=>({text:'Document 2'})});
        await flush();
        calls[0].resolve({ok:true,json:async()=>({text:'Wrong old document'})});
        await flush();
        assert.equal(d.querySelector('pre').textContent,'Document 2');
        w.eablageSelectDocumentPreview(panel,{pdfUrl:'archiv_pdf.php?dokid=3',textUrl:'text3'});
        w.eablageSelectDocumentPreview(panel,null);
        calls[2].resolve({ok:true,json:async()=>({text:'Cleared document'})});
        await flush();
        assert.equal(d.querySelector('pre').textContent,'');
        assert.equal(d.querySelector('iframe'),null);
        assert.equal(d.querySelector('[data-preview-open]').hasAttribute('href'),false);
        assert.equal(d.querySelector('[data-preview-empty]').hidden,false);
    } finally { f.close(); }
});
test('empty texts and failed requests are distinct, and Text allows a retry',async()=>{
    const f=mount(),{w,d,calls,panel}=f;
    try {
        w.eablageSelectDocumentPreview(panel,{pdfUrl:'archiv_pdf.php?dokid=1',textUrl:'text'});
        d.getElementById('viewTextButton').click();
        calls[0].resolve({ok:false});
        await flush();
        assert.match(d.querySelector('pre').textContent,/konnte nicht geladen/);
        d.getElementById('viewTextButton').click();
        calls[1].resolve({ok:true,json:async()=>({text:''})});
        await flush();
        assert.equal(d.querySelector('pre').textContent,'Kein Dokumenttext vorhanden.');
        assert.equal(d.querySelector('[data-preview-text]').hasAttribute('aria-busy'),false);
    } finally { f.close(); }
});
