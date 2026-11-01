/* Shared document selection for preview and the server-side unlink action. */
(() => {
    const root = document.querySelector('[data-vorgang-workspace]');
    if (!root) return;
    const links = Array.from(root.querySelectorAll('[data-case-preview]'));
    const pdf = document.getElementById('vorgang-preview-pdf');
    const actions = document.getElementById('vorgang-preview-actions');
    const empty = document.getElementById('vorgang-preview-empty');
    const open = document.getElementById('vorgang-preview-open');
    const edit = document.getElementById('vorgang-preview-edit');
    const detachForm = document.getElementById('vorgang-detach-form');
    const detachDocument = document.getElementById('vorgang-detach-document');
    const detachButton = document.getElementById('vorgang-detach');
    const mobile = window.matchMedia('(max-width: 760px)');
    let selected = null;
    function render() {
        if (!selected) return;
        detachDocument.value = selected.dataset.documentId;
        detachButton.disabled = false;
        detachButton.title = 'Zuordnung zum ausgewählten Dokument entfernen.';
        detachButton.setAttribute('aria-label', 'Zuordnung zu Dokument ' + selected.dataset.documentId + ' entfernen');
        actions.hidden = false;
        empty.hidden = true;
        open.href = selected.href;
        edit.href = selected.dataset.editUrl;
        if (mobile.matches) {
            pdf.replaceChildren();
            return;
        }
        if (pdf.firstElementChild?.getAttribute('src') === selected.getAttribute('href')) return;
        const frame = document.createElement('iframe');
        frame.title = 'Original-PDF für Dokument ' + selected.dataset.documentId;
        frame.src = selected.getAttribute('href');
        pdf.replaceChildren(frame);
    }
    links.forEach(link => link.addEventListener('click', event => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        selected = link;
        links.forEach(item => item.removeAttribute('aria-current'));
        link.setAttribute('aria-current', 'true');
        render();
    }));
    detachForm.addEventListener('submit', event => {
        if (!selected || detachDocument.value !== selected.dataset.documentId) event.preventDefault();
    });
    mobile.addEventListener('change', render);
})();
