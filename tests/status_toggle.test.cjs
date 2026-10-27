// Run without a database: node --test tests/status_toggle.test.cjs
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const script = fs.readFileSync(path.join(__dirname, '../web/status_toggle.js'), 'utf8');
function setup(kind, response, reject = false) {
    const handlers = {};
    const requests = [];
    function element() {
        return { children: [], style: {}, dataset: {}, classList: { toggle() {}, add() {} },
            setAttribute() {}, removeAttribute() {},
            append(child) { this.children.push(child); },
            replaceChildren(...children) { this.children = children; },
            remove() { this.removed = true; } };
    }
    const row = Object.assign(element(), { cells: Array(5), after(node) { this.feedback = node; },
        getBoundingClientRect: () => ({ height: 72 }) });
    const button = Object.assign(element(), { type:'submit', name:kind === 'master' || kind === 'delete' ? 'action' : '',
        value: kind === 'delete' ? 'delete' : 'deactivate', textContent:kind === 'rename' ? 'Speichern' : '✅ aktiv' });
    const input = { value: 'Neue Bezeichnung' };
    const activeField = { value: '0' };
    const controls = [button];
    controls.namedItem = name => name === 'name' ? input : name === 'aktiv' ? activeField : null;
    const actionValue = kind === 'rule' ? 'toggle' : kind === 'master' ? 'deactivate' : kind;
    const form = { action: {name:'action'}, elements:controls,
        dataset:kind === 'master' || kind === 'rule' ? {statusToggle:kind} : {rowAction:kind},
        querySelector: () => null,
        getAttribute: () => kind === 'rule' ? null : 'stammdaten.php',
        addEventListener: (name, fn) => { handlers[name] = fn; }, closest: () => row };
    vm.runInNewContext(script, {
        document: { querySelectorAll: () => [form], createElement: element },
        window: { location: { href:'https://example.test/autokat_regeln.php?seite=2' } },
        URLSearchParams, setTimeout: () => 1, clearTimeout() {},
        FormData: class {
            constructor() { this.values = new Map([['action',actionValue],['name',input.value],['kind','categories'],['csrf','token']]); }
            set(k,v) { this.values.set(k,v); } get(k) { return this.values.get(k); }
        },
        fetch: async (url, options) => {
            requests.push({url, options});
            assert.equal(button.disabled,true);
            if (reject) throw Error('connection lost');
            return response;
        }
    });
    return { handlers, requests, row, button, input, activeField };
}
for (const kind of ['master','rule','rename','delete']) {
    test(kind + ': correct endpoint and confirmed row update', async () => {
        const h = setup(kind,{ok:true,json:async()=>({ok:true,active:false})});
        await h.handlers.submit({preventDefault() {}});
        assert.equal(h.requests[0].url,kind === 'rule' ? 'https://example.test/autokat_regeln.php?seite=2' : 'stammdaten.php');
        assert.equal(h.requests[0].options.body.get('csrf'),'token');
        assert.equal(h.requests[0].options.body.get('status_inline'),'1');
        assert.equal(h.row.feedback.hidden,true);
        assert.equal(h.button.disabled,false);
        if (kind === 'rename') {
            assert.equal(h.button.textContent,'✓ Gespeichert');
            h.handlers.input();
            assert.equal(h.button.textContent,'Speichern');
        } else if (kind === 'delete') {
            assert.equal(h.row.children[0].textContent,'✓ Eintrag gelöscht');
            assert.equal(h.row.children[0].style.height,'72px');
        } else assert.equal(h.button.textContent,'⏸️ inaktiv');
    });
}
test('validation error spans the row and preserves input', async () => {
    const h = setup('rename',{ok:false,json:async()=>({ok:false,message:'Bezeichnung existiert bereits.'})});
    await h.handlers.submit({preventDefault() {}});
    assert.equal(h.row.feedback.hidden,false);
    assert.equal(h.row.feedback.children[0].colSpan,5);
    assert.equal(h.input.value,'Neue Bezeichnung');
    assert.equal(h.button.textContent,'Speichern');
    assert.equal(h.row.feedback.children[0].children[0].children[0].textContent,'Bezeichnung existiert bereits.');
});
test('network failure keeps the previous status', async () => {
    const h = setup('master',null,true);
    await h.handlers.submit({preventDefault() {}});
    assert.equal(h.button.textContent,'✅ aktiv');
    assert.equal(h.row.feedback.hidden,false);
});

