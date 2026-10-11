const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').join(__dirname, '../web/inbox_status.js'), 'utf8');
function setup(count, hidden = false, failure = false) {
    const elements = Array.from({length:count}, (_,i) => ({dataset:{inboxStatus:`Dokument ä ${i}.pdf`}, textContent:'vorher'}));
    const callbacks = [], calls = [];
    vm.runInNewContext(source, {URLSearchParams, document:{hidden, querySelectorAll:()=>elements},
        setTimeout:callback=>callbacks.push(callback), fetch:async (url, options)=>{
            const names = options.body.getAll('filenames[]'); calls.push({url, names, method:options.method});
            return {ok:!failure, json:async()=>({jobs:names.map(dateiname=>({dateiname,status:'bereit'}))})};
        }});
    return {elements, callbacks, calls};
}
test('poll only visible filenames in bounded batches', async()=>{
    const state=setup(203); await state.callbacks.shift()();
    assert.deepEqual(state.calls.map(c=>c.names.length),[200,3]);
    assert.equal(state.calls[0].method,'POST');
    assert.equal(state.calls[0].names[0],'Dokument ä 0.pdf');
    assert.ok(state.elements.every(el=>el.textContent==='Zur Prüfung bereit'));
});
test('hidden and empty inbox never query historical jobs', async()=>{
    for(const state of [setup(2,true),setup(0)]) {
        await state.callbacks.shift()(); assert.equal(state.calls.length,0);
    }
});
test('failed request retains current display and schedules next poll', async()=>{
    const state=setup(2,false,true); await state.callbacks.shift()();
    assert.ok(state.elements.every(el=>el.textContent==='vorher'));
    assert.equal(state.callbacks.length,1);
});
