// node --test tests/classification_draft.test.cjs
const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const code = fs.readFileSync(path.join(__dirname, '../web/classification_draft.js'), 'utf8');
function screen(href, storage, target = 'autokat_regel_bearbeiten.php?regel=R0001', sha = '') {
    const events = {};
    const editing = new URL(href).pathname.endsWith('/bearbeiten.php');
    const preview = {textContent: 'Gespeicherter Text'};
    const fields = [
        {name:'Partner',value:'Server-Vorbelegung',tagName:'INPUT'},
        {name:'Kategorie2',value:'3',tagName:'INPUT'},
        {name:'Kategorie2',value:'4',tagName:'SELECT', options:[{value:'4'}],selectedOptions:[{textContent:'Privat'}]},
        {name:'erkennungs_info',value:'AutoKat R0001',tagName:'INPUT'},
        {name:'original_datei',value:'a.pdf',tagName:'INPUT'},
        {name:'source_sha256',value:sha,tagName:'INPUT'}
    ];
    if (editing) fields.push(
        {name:'dokid',value:new URL(href).searchParams.get('dokid') || '42',tagName:'INPUT'},
        {name:'document_revision',value:'original-revision',tagName:'INPUT'},
        {name:'pending_document_text',value:'',tagName:'INPUT'},
        {name:'reclassification_info',value:'',tagName:'INPUT'}
    );
    const link = {href:target,addEventListener:(name,fn)=>{events.click=fn;}};
    const location = {href,assign(url){this.destination=url;}};
    const notices = [];
    const context = {
        document:{
            querySelector:selector=>selector === '#textDocumentView pre' ? preview : ({elements:fields,hasAttribute:()=>editing}),
            querySelectorAll:selector=>selector === 'a[data-draft-link]' ? [link] : [],
            getElementById:()=>({style:{display:'none'}})
        },
        location,sessionStorage:storage, URL, Uint8Array,
        crypto:{getRandomValues:array=>array.fill(7)},
        history:{state:null,replaceState(_state,_title,url){location.href=url;}},
        requestAnimationFrame:fn=>fn(),
        window:{scrollY:450,addEventListener(){},scrollTo(_x,y){this.restoredY=y;},alert:text=>notices.push(text)}
    };
    vm.runInNewContext(code,context);
    return {context,fields,events,location,notices,preview};
}
function storage() {
    const data = new Map();
    return {data,setItem:(k,v)=>data.set(k,v),getItem:k=>data.get(k)||null,removeItem:k=>data.delete(k)};
}
for(const target of ['autokat_regel_bearbeiten.php?regel=R0001','bearbeiten.php?dokid=42']) {
    test(target + ': round trip restores draft once without submission',()=>{
        const mem=storage();
        const first=screen('http://nas/eablage_test/verarbeiten.php?file=a.pdf',mem,target);
        first.fields[0].value='Ungespeicherter Partner';
        first.fields[1].value='8';
        first.events.click({button:0,preventDefault(){}});
        const destination=new URL(first.location.destination);
        const back=destination.searchParams.get('return');
        assert.match(back,/verarbeiten.php\?file=a.pdf&draft=/);
        assert.equal(mem.data.size,1);
        const returned=screen('http://nas/eablage_test/'+back,mem);
        assert.equal(returned.fields[0].value,'Ungespeicherter Partner');
        assert.equal(returned.fields[1].value,'8');
        assert.equal(returned.fields[2].value,'4');
        assert.equal(returned.context.window.restoredY,450);
        assert.equal(mem.data.size,0);
        assert.equal(new URL(returned.location.href).searchParams.has('draft'),false);
    });
}
test('blocked storage keeps the classification screen open',()=>{
    const s=screen('http://nas/eablage_test/verarbeiten.php?file=a.pdf',{
        setItem(){throw Error('full');},getItem(){return null;},removeItem(){}
    });
    s.events.click({button:0,preventDefault(){}});
    assert.equal(s.location.destination,undefined);
    assert.equal(s.notices.length,1);
});
test('a snapshot from another document is not applied',()=>{
    const mem=storage();
    const first=screen('http://nas/eablage_test/verarbeiten.php?file=a.pdf',mem);
    first.events.click({button:0,preventDefault(){}});
    const token=new URL(first.location.href).searchParams.get('draft');
    const other=screen('http://nas/eablage_test/verarbeiten.php?file=b.pdf&draft='+token,mem);
    assert.equal(other.context.window.restoredY,undefined);
    assert.equal(mem.data.size,0);
});


test('a replaced PDF never receives the previous file draft',()=>{
    const mem=storage();
    const first=screen('http://nas/eablage_test/verarbeiten.php?file=a.pdf',mem,undefined,'a'.repeat(64));
    first.fields[0].value='Old document partner';
    first.events.click({button:0,preventDefault(){}});
    const back=new URL(first.location.destination).searchParams.get('return');
    const returned=screen('http://nas/eablage_test/'+back,mem,undefined,'b'.repeat(64));
    assert.equal(returned.fields[0].value,'Server-Vorbelegung');
    assert.equal(mem.data.size,0);
});

test('editing: history round trip retains unsaved fields, text and original revision',()=>{
    const mem=storage();
    const first=screen('http://nas/eablage_test/bearbeiten.php?dokid=42&return=explorer.php%3Fjahr%3D2026',mem,'protokoll.php?dokid=42');
    first.fields.find(el=>el.name==='Partner').value='Ungespeicherter Partner';
    first.fields.find(el=>el.name==='pending_document_text').value='Neu eingelesener Text';
    first.fields.find(el=>el.name==='reclassification_info').value='AutoKat:E[R0042/v2/r30]';
    first.events.click({button:0,preventDefault(){}});
    const destination=new URL(first.location.destination);
    assert.equal(destination.pathname,'/eablage_test/protokoll.php');
    const back=destination.searchParams.get('return');
    assert.match(back,/bearbeiten.php\?dokid=42/);
    assert.equal(new URL(back,'http://nas/').searchParams.get('return'),'explorer.php?jahr=2026');
    // A concurrent revision must remain detectable on save, not silently replaced.
    const key=[...mem.data.keys()][0];
    assert.match(key,/^eablage.document-draft:/);
    const snapshot=JSON.parse(mem.getItem(key));
    snapshot.fields.find(el=>el.name==='document_revision').value='previous-revision';
    mem.setItem(key,JSON.stringify(snapshot));
    const returned=screen('http://nas/eablage_test/'+back,mem,'protokoll.php?dokid=42');
    assert.equal(returned.fields.find(el=>el.name==='Partner').value,'Ungespeicherter Partner');
    assert.equal(returned.fields.find(el=>el.name==='document_revision').value,'previous-revision');
    assert.equal(returned.fields.find(el=>el.name==='reclassification_info').value,'AutoKat:E[R0042/v2/r30]');
    assert.equal(returned.preview.textContent,'Neu eingelesener Text');
    assert.equal(mem.data.size,0);
});
test('an editing draft is never applied to another document',()=>{
    const mem=storage();
    const first=screen('http://nas/eablage_test/bearbeiten.php?dokid=42',mem,'protokoll.php?dokid=42');
    first.fields[0].value='Wrong document';
    first.events.click({button:0,preventDefault(){}});
    const token=new URL(first.location.href).searchParams.get('draft');
    const other=screen('http://nas/eablage_test/bearbeiten.php?dokid=43&draft='+token,mem);
    assert.equal(other.fields[0].value,'Server-Vorbelegung');
    assert.equal(mem.data.size,0);
});

test('history link after a POST restores a valid GET URL and original navigation',()=>{
    const mem=storage();
    const first=screen('http://nas/eablage_test/bearbeiten.php',mem,'protokoll.php?dokid=42');
    first.fields.push({name:'return',value:'dokumente.php?suche=Versicherung',tagName:'INPUT'});
    first.events.click({button:0,preventDefault(){}});
    const back=new URL(new URL(first.location.destination).searchParams.get('return'),'http://nas/eablage_test/');
    assert.equal(back.searchParams.get('dokid'),'42');
    assert.equal(back.searchParams.get('return'),'dokumente.php?suche=Versicherung');
    const returned=screen(back.href,mem);
    assert.equal(returned.fields.find(el=>el.name==='document_revision').value,'original-revision');
    assert.equal(mem.data.size,0);
});
