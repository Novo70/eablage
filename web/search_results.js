/* Reveal invalid criteria before the browser focuses the affected control. */
(() => {
    const form = document.getElementById('search-refine-form');
    const filter = document.getElementById('search-refine');
    if (!form || !filter) return;
    form.addEventListener('invalid', () => { filter.open = true; }, true);
})();

(() => {
    const table = document.querySelector('[data-search-selection]');
    const dialog = document.getElementById('search-case-dialog');
    if (!table || typeof dialog?.showModal !== 'function') return;

    const storageKey = 'eablage.search-selection.' + table.dataset.searchSelection;
    const limit = 1000;
    const selected = new Set();
    function restoreSelection() {
        try {
            const saved = JSON.parse(sessionStorage.getItem(storageKey) || '[]');
            if (!Array.isArray(saved)) return;
            selected.clear();
            saved.filter(id => typeof id === 'string' && /^[1-9][0-9]{0,9}$/.test(id))
                .slice(0, limit).forEach(id => selected.add(id));
        } catch { /* The current page remains usable if storage is unavailable. */ }
    }
    restoreSelection();

    const rows = [...table.querySelectorAll('[data-search-document]')];
    if (!rows.length) return;
    const pageIds = [...new Set(rows.map(box => box.dataset.searchDocument))];
    const pageBoxes = [...document.querySelectorAll('[data-search-page-checkbox]')];
    const count = document.getElementById('search-selection-count');
    const clear = document.getElementById('search-selection-clear');
    const open = document.getElementById('search-case-open');
    const form = document.getElementById('search-case-form');
    const summary = document.getElementById('search-case-summary');
    const error = document.getElementById('search-case-error');
    const save = form.querySelector('[type="submit"]');
    const cancel = document.getElementById('search-case-cancel');
    let saving = false;

    function selectionText() {
        return selected.size === 1 ? '1 Dokument ausgewählt' : selected.size + ' Dokumente ausgewählt';
    }

    function render() {
        rows.forEach(box => {
            box.checked = selected.has(box.dataset.searchDocument);
            box.closest('tr').classList.toggle('ea-search-selected', box.checked);
        });
        const onPage = pageIds.filter(id => selected.has(id)).length;
        pageBoxes.forEach(box => {
            box.checked = onPage === pageIds.length;
            box.indeterminate = onPage > 0 && !box.checked;
        });
        count.textContent = selected.size ? selectionText() : 'Dokumente auswählen';
        clear.disabled = open.disabled = selected.size === 0;
    }

    function change(ids, checked) {
        const next = new Set(selected);
        ids.forEach(id => checked ? next.add(id) : next.delete(id));
        if (next.size > limit) {
            render();
            count.textContent += ' · Höchstens 1.000 Dokumente pro Vorgang.';
            return;
        }
        selected.clear();
        next.forEach(id => selected.add(id));
        try {
            if (selected.size) sessionStorage.setItem(storageKey, JSON.stringify([...selected]));
            else sessionStorage.removeItem(storageKey);
        } catch { /* Keep the in-memory selection on the current page. */ }
        render();
    }

    rows.forEach(box => box.addEventListener('change', () => change([box.dataset.searchDocument], box.checked)));
    pageBoxes.forEach(box => box.addEventListener('change', () => change(pageIds, box.checked)));
    clear.addEventListener('click', () => {
        change([...selected], false);
        pageBoxes.find(box => box.getClientRects().length)?.focus();
    });
    document.getElementById('search-new').addEventListener('click', () => change([...selected], false));
    open.addEventListener('click', () => {
        summary.textContent = selectionText() + '.';
        error.hidden = true;
        dialog.showModal();
    });
    cancel.addEventListener('click', () => dialog.close());
    dialog.addEventListener('cancel', event => { if (saving) event.preventDefault(); });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (saving || !selected.size) return;
        saving = save.disabled = cancel.disabled = true;
        error.hidden = true;
        try {
            const data = new FormData(form);
            const returnUrl = data.get('return');
            data.delete('return');
            data.set('document_ids', JSON.stringify([...selected]));
            const response = await fetch(form.action, {method: 'POST', body: data});
            const result = await response.json();
            if (!response.ok || result.ok !== true || !Number.isInteger(result.id) || result.id < 1) {
                throw new Error(result.error || 'Der Vorgang konnte nicht gespeichert werden.');
            }
            change([...selected], false);
            location.assign('vorgaenge.php?' + new URLSearchParams({id: result.id, return: returnUrl}));
        } catch (failure) {
            error.textContent = failure instanceof SyntaxError || failure instanceof TypeError
                ? 'Speichern konnte nicht bestätigt werden. Bitte die Vorgangsübersicht prüfen.'
                : failure.message;
            error.hidden = false;
        } finally {
            saving = save.disabled = cancel.disabled = false;
        }
    });

    table.querySelectorAll('.ea-search-selection-cell').forEach(cell => { cell.hidden = false; });
    document.querySelector('[data-search-mobile-selection]').hidden = false;
    render();
    // Returning through browser history may restore a page with a stale selection.
    window.addEventListener('pageshow', event => {
        if (!event.persisted) return;
        restoreSelection();
        render();
    });
})();
