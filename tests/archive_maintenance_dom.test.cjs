const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const vm = require('node:vm');
const { parseHTML } = require('linkedom');

async function setup(t, { count = 250, respond }) {
    const calls = [];
    const fetch = async (url, options) => {
        assert.equal(typeof url, 'string', 'Formularfeld action darf die Zieladresse nicht verdecken');
        assert.equal(url, 'https://archive.test/gesamtexport.php');
        const body = options.body;
        const action = body.get('action');
        const items = JSON.parse(body.get('selection_json') || '[]');
        assert.equal(body.get('aktion_token'), 'csrf-test');
        assert.equal(body.getAll('selection_json').length <= 1, true);
        calls.push({ action, items });
        const response = respond ? respond(action, items, calls.length) :
            { results: items.map(item => ({ ...item, success: true })) };
        return { ok: !response.httpStatus || response.httpStatus === 200, json: async () => response };
    };
    const hidden = '<input name="aktion_token" value="csrf-test" type="hidden"><input name="action" value="test-action" type="hidden">';
    const options = Array.from({ length: count }, (_, i) =>
        `<input type="checkbox" name="archive_selection[]" value='${JSON.stringify({ dok_id: i + 1, token: 'signed-' + i })}'>`).join('');
    const repair = `<div id="archivpruefung"><form id="audit-form"><button>Archiv prüfen</button></form>
        <form id="archive-repair-form" action="https://archive.test/gesamtexport.php">${hidden}
        <div class="archive-bulk-controls" hidden><button type="button" data-archive-select="all">Alle auswählen</button>
        <button type="button" data-archive-select="none">Auswahl aufheben</button><span data-archive-count></span></div>
        ${options}<button type="submit">Reparieren</button><p data-archive-progress></p><ul data-archive-results></ul></form></div>`;
    const { document, window } = parseHTML(repair);
    window.confirm = () => true;
    document.querySelectorAll('form').forEach(form => {
        form.elements = { aktion_token: form.querySelector('[name="aktion_token"]') };
        // Native HTMLFormElement named access exposes the input instead of the URL.
        // LinkeDOM does not reproduce this automatically.
        form.action = form.querySelector('[name="action"]');
        // LinkeDOM does not implement browser constraint validation.
    });
    const context = vm.createContext({ document, window, fetch, URLSearchParams });
    vm.runInContext(fs.readFileSync(path.resolve(__dirname, '../web/archiv_wartung.js'), 'utf8'), context);
    const page = {
        async click(selector) {
            const node = document.querySelector(selector);
            assert(node, selector);
            if (node.disabled) return;
            const event = new window.Event(node.getAttribute('type') === 'submit' ? 'submit' : 'click', { cancelable: true });
            (node.getAttribute('type') === 'submit' ? node.closest('form') : node).dispatchEvent(event);
        },
        async check(selector) { document.querySelector(selector).checked = true; },
        async textContent(selector) { return document.querySelector(selector).textContent; },
        async isDisabled(selector) { return !!document.querySelector(selector).disabled; },
        locator(selector) { return { count: async () => document.querySelectorAll(selector).length }; },
        async waitForFunction(fn) {
            for (let i = 0; i < 100; i++) {
                if (vm.runInContext('(' + fn.toString() + ')()', context)) return;
                await new Promise(resolve => setTimeout(resolve, 5));
            }
            throw new Error('Expected completion status was not reached.');
        }
    };
    return { page, calls, document, window };
}

test('3500 selections: select/clear, serialize without input truncation, sequential batches of 100', async t => {
    const { page, calls } = await setup(t, { count: 3500 });
    await page.click('[data-archive-select="all"]');
    assert.equal(await page.textContent('[data-archive-count]'), '3500 ausgewählt');
    await page.click('[data-archive-select="none"]');
    assert.equal(await page.textContent('[data-archive-count]'), '0 ausgewählt');
    await page.click('[data-archive-select="all"]');
    await page.click('#archive-repair-form button[type="submit"]');
    await page.waitForFunction(() => document.querySelector('[data-archive-progress]').textContent.includes('Fertig.'));
    assert.equal(calls.length, 35);
    assert(calls.every(call => call.items.length === 100 && call.action === 'archive_repair_batch'));
    assert.deepEqual(calls.flatMap(call => call.items.map(item => item.dok_id)), Array.from({ length: 3500 }, (_, i) => i + 1));
    assert.equal(await page.isDisabled('#audit-form button'), false);
    assert.equal(await page.isDisabled('#archive-repair-form button[type="submit"]'), true);
});

test('empty selection does not start a repair', async t => {
    const { page, calls } = await setup(t, {});
    await page.click('#archive-repair-form button[type="submit"]');
    assert.equal(calls.length, 0);
    assert.match(await page.textContent('[data-archive-progress]'), /mindestens eine/);
});

test('repair stops after a failed package, exposes detail safely and allows a new audit', async t => {
    const { page, calls } = await setup(t, { respond: (action, items) => ({
        results: items.map((item, i) => ({ ...item, success: i !== 0, message: '<script>failure</script>' }))
    }) });
    await page.click('[data-archive-select="all"]');
    await page.click('#archive-repair-form button[type="submit"]');
    await page.waitForFunction(() => document.querySelector('[data-archive-progress]').textContent.includes('gestoppt'));
    assert.equal(calls.length, 1);
    assert.match(await page.textContent('[data-archive-results]'), /<script>failure<\/script>/);
    assert.equal(await page.locator('[data-archive-results] script').count(), 0);
    assert.equal(await page.isDisabled('#audit-form button'), false);
});

test('HTTP failure does not retry or start another package', async t => {
    const { page, calls } = await setup(t, { respond: () => ({ httpStatus: 400, detail: 'Sitzung ungültig' }) });
    await page.click('[data-archive-select="all"]');
    await page.click('#archive-repair-form button[type="submit"]');
    await page.waitForFunction(() => document.querySelector('[data-archive-progress]').textContent.includes('gestoppt'));
    assert.equal(calls.length, 1);
    assert.match(await page.textContent('[data-archive-progress]'), /Sitzung ungültig/);
});
