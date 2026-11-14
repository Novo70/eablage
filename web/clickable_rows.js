/* Keep native links and text selection available inside clickable table rows. */
document.querySelectorAll('[data-clickable-rows]').forEach(table => {
    table.classList.add('ea-results-clickable');
    table.addEventListener('click', event => {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const row = event.target.closest('tbody tr');
        if (!row || event.target.closest('a, input, button, label, select, textarea')) return;

        const selection = window.getSelection();
        if (selection?.rangeCount && !selection.isCollapsed && selection.getRangeAt(0).intersectsNode(row)) return;

        const link = row.querySelector('a[data-row-link]');
        if (link) location.assign(link.href);
    });
});
