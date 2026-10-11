// node --test tests/explorer.test.cjs
const {test} = require('node:test');
const assert = require('node:assert/strict');
const explorer = require('../web/explorer.js');
const plan = items => ({profile: 1, directory_structure: true, items});
const item = (id, dirs, name = 'Datei.pdf', display = {}) => ({dok_id: id, directories: dirs, filename: name,
    display: {date: '2026-09-24', category: 'Finanzen', type: 'Beleg', partner: 'Partner', subject: name.slice(0, -4), number: '', ...display}});

test('five levels, files at intermediate nodes and root are retained exactly', () => {
    const source = plan([item(1, []), item(2, ['A']), item(3, ['A', '2026', '09 - September', 'Rechnung', 'Müller'])]);
    const before = JSON.stringify(source);
    const model = explorer.buildTree(source);
    assert.equal(model.tree.count, 3);
    assert.equal(model.tree.documents[0].dok_id, 1);
    assert.equal(model.tree.children.get('A').documents[0].dok_id, 2);
    assert.equal(model.documents.get(3).node.parts.length, 5);
    assert.equal(JSON.stringify(source), before);
});

test('backend filenames including collision suffixes are never recalculated', () => {
    const model = explorer.buildTree(plan([item(2, ['A'], 'Test - DokID 2.pdf'), item(1, ['A'], 'Test.pdf')]));
    assert.equal(model.documents.get(2).filename, 'Test - DokID 2.pdf');
    assert.equal(model.documents.get(1).filename, 'Test.pdf');
});

test('literal labels are separate nodes even for prototype names or path-like text', () => {
    const model = explorer.buildTree(plan([item(1, ['__proto__']), item(2, ['a/b']), item(3, ['a', 'b']), item(4, ['<img src=x onerror=alert(1)>'])]));
    assert.equal(model.tree.children.size, 4);
    assert.notEqual(model.documents.get(2).node, model.documents.get(3).node);
});

test('URL round trip preserves special characters and only DokID selects the PDF', () => {
    const model = explorer.buildTree(plan([item(17, ['Müller & Söhne', '09 - September'], 'a # & ?.pdf')]));
    const doc = model.documents.get(17);
    const url = explorer.viewUrl(doc.node, doc);
    const restored = explorer.selectionFromUrl(model, 'http://nas/app/' + url);
    assert.equal(restored.document, doc);
    const links = explorer.documentLinks(doc.node, doc);
    assert.equal(links.pdf, 'archiv_pdf.php?dokid=17');
    const edit = new URL(links.edit, 'http://nas/app/');
    assert.equal(edit.searchParams.get('return'), url);
    assert.equal(edit.searchParams.get('dokid'), '17');
});

test('after editing or settings changes selected document follows its current folder', () => {
    const old = explorer.buildTree(plan([item(1, ['Alt'])]));
    const url = explorer.viewUrl(old.documents.get(1).node, old.documents.get(1));
    const updated = explorer.buildTree(plan([item(1, ['Neu', '2027'])]));
    const state = explorer.selectionFromUrl(updated, 'http://nas/' + url);
    assert.deepEqual(state.node.parts, ['Neu', '2027']);
    assert.equal(state.document.dok_id, 1);
});

test('deleted selection falls back to nearest surviving ancestor', () => {
    const model = explorer.buildTree(plan([item(2, ['A'])]));
    const state = explorer.selectionFromUrl(model, 'http://nas/explorer.php?dokid=1&folder=' + encodeURIComponent(JSON.stringify(['A', 'Removed'])));
    assert.equal(state.document, null);
    assert.equal(state.missing, true);
    assert.deepEqual(state.node.parts, ['A']);
});

test('empty or flat plans and malformed navigation work without exceptions', () => {
    const empty = explorer.buildTree(plan([]));
    for (const query of ['folder=bad', 'folder={}', 'folder=[1]', 'dokid=bad', 'folder=null']) {
        assert.equal(explorer.selectionFromUrl(empty, 'http://nas/explorer.php?' + query).node, empty.tree);
    }
    assert.equal(explorer.buildTree(plan([item(1, []), item(2, [])])).tree.documents.length, 2);
});

test('invalid plans and ambiguous IDs are rejected as a whole', () => {
    for (const value of [null, {items: []}, plan([item(0, [])]), plan([item(1, []), item(1, ['B'])]), plan([item(1, [null])])]) {
        assert.throws(() => explorer.buildTree(value));
    }
});

test('filter matches visible metadata case-insensitively in this subtree only', () => {
    const model = explorer.buildTree(plan([
        item(1, ['Konto', '2025'], 'Auszug AB123.pdf'),
        item(2, ['Konto', '2025', 'September'], 'auszug ab123 - 09.pdf'),
        item(3, ['Konto', '2024'], 'AB123.pdf'),
        item(4, ['Konto', '2025', 'AB123'], 'Andere.pdf', {subject: 'Andere'}),
        item(5, ['Konto', '2025'], 'Versteckt.pdf', {partner: 'Müller [1]', number: '4711'})
    ]));
    const node = model.documents.get(1).node;
    assert.deepEqual(explorer.listEntries(node, ' ab123 ').map(x => x.doc.dok_id).sort(), [1, 2]);
    assert.equal(explorer.listEntries(node, '[1]')[0].doc.dok_id, 5);
    assert.equal(explorer.listEntries(node, 'MU\u0308LLER')[0].doc.dok_id, 5);
    assert.equal(explorer.listEntries(node, '4711')[0].doc.dok_id, 5);
    assert.deepEqual(explorer.listEntries(node, 'finanzen').map(x => x.doc.dok_id).sort(), [1, 2, 4, 5]);
    assert.equal(explorer.listEntries(node, 'Andere.pdf').length, 0);
    assert.equal(explorer.listEntries(node, 'none').length, 0);
    assert.ok(explorer.listEntries(node, '').some(x => x.node));
});

test('filtered selection round trip keeps scope, but follows a renamed document', () => {
    const model = explorer.buildTree(plan([item(1, ['Konto', '2025', 'September'], 'AB123.pdf')]));
    const scope = model.tree.children.get('Konto').children.get('2025');
    const doc = model.documents.get(1);
    const links = explorer.documentLinks(scope, doc, 'ab123');
    const back = new URL(links.edit, 'http://nas/').searchParams.get('return');
    const state = explorer.selectionFromUrl(model, 'http://nas/' + back);
    assert.equal(state.node, scope);
    assert.equal(state.query, 'ab123');
    const renamed = explorer.buildTree(plan([item(1, ['Neu'], 'Renamed.pdf')]));
    const next = explorer.selectionFromUrl(renamed, 'http://nas/' + back);
    assert.deepEqual(next.node.parts, ['Neu']);
    assert.equal(next.query, '');
    assert.equal(next.document.dok_id, 1);
});


test('documents sort by visible date instead of configurable export filename, including filtered results', () => {
    const model = explorer.buildTree(plan([
        item(10, ['Konto'], 'A.pdf', {date: '2025-01-01', subject: 'Kontoauszug'}),
        item(30, ['Konto'], 'Z.pdf', {date: '2026-09-01', subject: 'Kontoauszug'}),
        item(20, ['Konto', 'September'], 'B.pdf', {date: '2026-08-01', subject: 'Kontoauszug'}),
        item(40, ['Konto'], 'C.pdf', {date: null, subject: 'Kontoauszug'})
    ]));
    const folder = model.tree.children.get('Konto');
    assert.deepEqual(folder.documents.map(doc => doc.dok_id), [30, 10, 40]);
    assert.deepEqual(explorer.listEntries(folder, 'Kontoauszug').map(entry => entry.doc.dok_id), [30, 20, 10, 40]);
    assert.equal(model.documents.get(30).filename, 'Z.pdf');
});

test('equal dates use displayed metadata and DokID for stable order', () => {
    const model = explorer.buildTree(plan([
        item(3, [], 'A.pdf', {partner: 'Zeta', subject: 'Beleg'}),
        item(2, [], 'Z.pdf', {partner: 'Alpha', subject: 'Beleg'}),
        item(1, [], 'B.pdf', {partner: 'Alpha', subject: 'Beleg'})
    ]));
    assert.deepEqual(model.tree.documents.map(doc => doc.dok_id), [1, 2, 3]);
});
