const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../web/preview_layout.js'), 'utf8');

function mount({saved = null, eligible = true, desktopEligible = true, blocked = false, count = 1} = {}) {
    const root = {dataset: {}};
    const buttons = Array.from({length: count}, () => ({
        attributes: {},
        icon: {setAttribute(key, value) { this[key] = value; }},
        querySelector(selector) { assert.equal(selector, 'use'); return this.icon; },
        setAttribute(key, value) { this.attributes[key] = value; },
        addEventListener(type, listener) { assert.equal(type, 'click'); this.click = listener; },
    }));
    let stored = saved;
    let mediaListener;
    const media = {matches: eligible, addEventListener(type, listener) {
        assert.equal(type, 'change'); mediaListener = listener;
    }};
    let desktopListener;
    const desktopMedia = {matches: desktopEligible, addEventListener(type, listener) {
        assert.equal(type, 'change'); desktopListener = listener;
    }};
    vm.runInNewContext(source, {
        document: {documentElement: root, querySelectorAll(selector) {
            assert.equal(selector, '[data-preview-split-toggle]'); return buttons;
        }},
        window: {
            matchMedia(query) {
                if (query === '(min-width: 1280px)') return desktopMedia;
                assert.equal(query, '(min-width: 1600px)');
                return media;
            },
            get localStorage() {
                if (blocked) throw new Error('Storage blocked');
                return {
                    getItem(key) { assert.equal(key, 'eablage.previewSplit'); return stored; },
                    setItem(key, value) { assert.equal(key, 'eablage.previewSplit'); stored = value; },
                };
            },
        },
    });
    return {
        root, buttons, get saved() { return stored; },
        resize(eligible) { media.matches = eligible; mediaListener(); },
        setSidebar(value) { desktopMedia.matches = value; desktopListener(); },
    };
}

test('starts balanced and toggles both ways with a browser-local preference', () => {
    const state = mount();
    assert.equal(state.root.dataset.eaPreviewSplit, 'balanced');
    assert.equal(state.buttons[0].hidden, false);
    assert.equal(state.buttons[0].icon.href, 'icons.svg#expand-horizontal');
    assert.equal(state.buttons[0].title, 'Vorschau verbreitern');
    state.buttons[0].click();
    assert.equal(state.root.dataset.eaPreviewSplit, 'wide');
    assert.equal(state.saved, 'wide');
    assert.equal(state.buttons[0].attributes['aria-pressed'], 'true');
    assert.equal(state.buttons[0].icon.href, 'icons.svg#collapse-horizontal');
    assert.equal(state.buttons[0].title, 'Vorschau schmaler anzeigen');
    assert.equal(state.buttons[0].attributes['aria-label'], state.buttons[0].title);
    state.buttons[0].click();
    assert.equal(state.saved, 'balanced');
    assert.equal(state.buttons[0].attributes['aria-pressed'], 'false');
    assert.equal(state.buttons[0].icon.href, 'icons.svg#expand-horizontal');
    assert.equal(state.buttons[0].attributes['aria-label'], 'Vorschau verbreitern');
});

test('restores the selection on another page and ignores unknown stored values', () => {
    assert.equal(mount({saved: 'wide'}).root.dataset.eaPreviewSplit, 'wide');
    assert.equal(mount({saved: 'invalid'}).root.dataset.eaPreviewSplit, 'balanced');
});

test('narrow desktop windows keep a disabled control and preserve the preference', () => {
    const state = mount({saved: 'wide'});
    state.resize(false);
    assert.equal(state.buttons[0].hidden, false);
    assert.equal(state.buttons[0].disabled, true);
    assert.match(state.buttons[0].title, /1600/);
    assert.equal(state.buttons[0].attributes['aria-pressed'], 'false');
    state.buttons[0].click();
    assert.equal(state.saved, 'wide');
    state.resize(true);
    assert.equal(state.buttons[0].hidden, false);
    assert.equal(state.buttons[0].disabled, false);
    assert.equal(state.buttons[0].attributes['aria-pressed'], 'true');
});

test('windows without the side navigation keep the control hidden', () => {
    assert.equal(mount({desktopEligible: false}).buttons[0].hidden, true);
});

test('resizing across the side navigation breakpoint updates control availability', () => {
    const state = mount({desktopEligible: false, saved: 'wide'});
    assert.equal(state.buttons[0].hidden, true);
    state.buttons[0].click();
    assert.equal(state.saved, 'wide');
    state.setSidebar(true);
    assert.equal(state.buttons[0].hidden, false);
    state.setSidebar(false);
    assert.equal(state.buttons[0].hidden, true);
    assert.equal(state.saved, 'wide');
});

test('blocked local storage still allows a temporary selection', () => {
    const state = mount({blocked: true});
    state.buttons[0].click();
    assert.equal(state.root.dataset.eaPreviewSplit, 'wide');
    state.buttons[0].click();
    assert.equal(state.root.dataset.eaPreviewSplit, 'balanced');
});

test('multiple controls show the same current selection', () => {
    const state = mount({count: 2});
    state.buttons[1].click();
    for (const button of state.buttons) {
        assert.equal(button.attributes['aria-pressed'], 'true');
        assert.equal(button.icon.href, 'icons.svg#collapse-horizontal');
    }
});

test('pages without a preview do not access storage or install observers', () => {
    assert.doesNotThrow(() => mount({count: 0, blocked: true}));
});



