/* One-use, tab-local snapshots for excursions from classification or editing.
   No request is sent and no document is saved when following a badge. */
(() => {
    const form = document.querySelector('[data-classification-draft], [data-document-draft]');
    if (!form) return;
    const editing = form.hasAttribute('data-document-draft');
    const prefix = editing ? 'eablage.document-draft:' : 'eablage.classification-draft:';
    const maxAge = 24 * 60 * 60 * 1000;
    const names = new Set(['Datum', 'Partner', 'Betreff', 'Doktyp', 'Kategorie',
        'Kategorie2', 'Nummer', 'Tags', 'dokument_text', 'erkennungs_info',
        'document_revision', 'reclassification_info', 'pending_document_text']);
    const controls = () => Array.from(form.elements).filter(el => names.has(el.name));
    const identity = () => {
        const url = new URL(location.href);
        if (editing) return url.pathname + '?dokid=' + encodeURIComponent(url.searchParams.get('dokid') || Array.from(form.elements).find(el => el.name === 'dokid')?.value || '');
        const source = Array.from(form.elements).find(el => el.name === 'source_sha256')?.value || '';
        return url.pathname + '?file=' + encodeURIComponent(url.searchParams.get('file') || '') + (source ? '&sha256=' + source : '');
    };
    const cleanUrl = () => {
        const url = new URL(location.href);
        url.searchParams.delete('draft');
        history.replaceState(history.state, '', url.href);
    };
    const clearCurrent = () => {
        const token = new URL(location.href).searchParams.get('draft');
        if (token) {
            try { sessionStorage.removeItem(prefix + token); } catch (_) {}
            cleanUrl();
        }
    };
    function restore() {
        const token = new URL(location.href).searchParams.get('draft');
        if (!token) return;
        try {
            const raw = sessionStorage.getItem(prefix + token);
            if (!raw) { cleanUrl(); return; }
            const saved = JSON.parse(raw);
            if (saved.identity !== identity() || Date.now() - saved.time > maxAge) {
                clearCurrent();
                return;
            }
            const fields = controls();
            for (const entry of saved.fields) {
                const el = fields[entry.index];
                if (!el || el.name !== entry.name) continue;
                if (el.tagName === 'SELECT' && !Array.from(el.options).some(option => option.value === entry.value)) {
                    // Preserve the previous choice visibly; server validation still applies.
                    el.add(new Option(entry.label + ' (bitte prüfen)', entry.value));
                }
                el.value = entry.value;
            }
            if (editing) {
                const pending = fields.find(el => el.name === 'pending_document_text')?.value;
                const preview = document.querySelector('#textDocumentView pre');
                if (pending && preview) preview.textContent = pending;
                if (form.dispatchEvent) form.dispatchEvent(new Event('input', {bubbles: true}));
            }
            if (typeof window.eablageSetDocumentView === 'function') {
                window.eablageSetDocumentView(saved.textView ? 'text' : 'pdf');
            }
            document.querySelectorAll('details').forEach((el, index) => {
                if (typeof saved.details[index] === 'boolean') el.open = saved.details[index];
            });
            clearCurrent();
            requestAnimationFrame(() => window.scrollTo(0, saved.scrollY));
        } catch (_) {
            window.alert('Die zwischengespeicherten Eingaben konnten nicht wiederhergestellt werden. Bitte den Eingabestand prüfen.');
        }
    }
    // Expire abandoned excursions, without touching other application storage.
    try {
        for (const key of Object.keys(sessionStorage)) {
            if (!key.startsWith(prefix)) continue;
            try {
                if (Date.now() - JSON.parse(sessionStorage.getItem(key)).time > maxAge) sessionStorage.removeItem(key);
            } catch (_) { sessionStorage.removeItem(key); }
        }
    } catch (_) {}
    restore();
    // Also consume the snapshot when the browser restores this page from its cache.
    window.addEventListener('pageshow', event => { if (event.persisted) restore(); });
    document.querySelectorAll('a[data-draft-link]').forEach(link => {
        link.addEventListener('click', event => {
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            try {
                const token = Array.from(crypto.getRandomValues(new Uint8Array(16)), n => n.toString(16).padStart(2, '0')).join('');
                const saved = {
                    identity: identity(), time: Date.now(), scrollY: window.scrollY,
                    textView: document.getElementById('textDocumentView')?.style.display !== 'none',
                    details: Array.from(document.querySelectorAll('details'), el => el.open),
                    fields: controls().map((el, index) => ({
                        index, name: el.name, value: el.value,
                        label: el.tagName === 'SELECT' ? (el.selectedOptions[0]?.textContent || el.value) : ''
                    }))
                };
                sessionStorage.setItem(prefix + token, JSON.stringify(saved));
                const back = new URL(location.href);
                if (editing) {
                    back.searchParams.set('dokid', Array.from(form.elements).find(el => el.name === 'dokid')?.value || back.searchParams.get('dokid') || '');
                    const origin = Array.from(form.elements).find(el => el.name === 'return')?.value;
                    if (origin) back.searchParams.set('return', origin);
                }
                back.searchParams.set('draft', token);
                const target = new URL(link.href, location.href);
                target.searchParams.set('return', back.pathname.split('/').pop() + back.search);
                history.replaceState(history.state, '', back.href);
                location.assign(target.href);
            } catch (_) {
                window.alert('Die Eingaben konnten nicht zwischengespeichert werden. Der Bildschirm bleibt geöffnet; es wurde nichts gespeichert.');
            }
        });
    });
    document.querySelectorAll('[data-draft-cancel]').forEach(link => link.addEventListener('click', clearCurrent));
    // Snapshots are consumed on return, so a later save/failure cannot resurrect them.
})();
