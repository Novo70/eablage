/* Shared document selection for preview and the server-side unlink action. */
(() => {
    const root = document.querySelector('[data-vorgang-workspace]');
    if (!root) return;
    const links = Array.from(root.querySelectorAll('[data-case-preview]'));
    const pdf = document.getElementById('vorgang-preview-pdf');
    const view = document.getElementById('vorgang-preview-view');
    const detachForm = document.getElementById('vorgang-detach-form');
    const detachDocument = document.getElementById('vorgang-detach-document');
    const detachButton = document.getElementById('vorgang-detach');
    let selected = null;
    function select(link) {
        selected = link;
        links.forEach(item => item.removeAttribute('aria-current'));
        link.setAttribute('aria-current', 'true');
        detachDocument.value = selected.dataset.documentId;
        detachButton.disabled = false;
        detachButton.title = 'Zuordnung zum ausgewählten Dokument entfernen.';
        detachButton.setAttribute('aria-label', 'Zuordnung zu Dokument ' + selected.dataset.documentId + ' entfernen');
        view.href = selected.dataset.viewUrl;
        view.removeAttribute('aria-disabled');
        view.removeAttribute('role');
        view.removeAttribute('title');
        window.eablageSelectDocumentPreview(pdf.closest('[data-document-preview]'), {
            pdfUrl: selected.getAttribute('href'),
            textUrl: 'dokument_text.php?dokid=' + encodeURIComponent(selected.dataset.documentId),
        });
    }
    links.forEach(link => link.addEventListener('click', event => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        select(link);
    }));
    const requestedId = new URLSearchParams(location.search).get('selected');
    const requestedDocument = links.find(link => link.dataset.documentId === requestedId);
    if (requestedDocument) select(requestedDocument);

    detachForm.addEventListener('submit', event => {
        if (!selected || detachDocument.value !== selected.dataset.documentId) event.preventDefault();
    });
})();
