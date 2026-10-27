/* Reorder the existing rows without submitting forms or discarding edits. */
(() => {
    const table = document.querySelector('[data-master-sort]');
    if (!table) return;
    const body = table.tBodies[0];
    const buttons = [...table.querySelectorAll('[data-sort-key]')];
    const collator = new Intl.Collator('de', {sensitivity: 'base'});
    const sort = key => {
        const rows = [...body.rows].filter(row => row.querySelector('[name="name"]'));
        const feedback = new Map(rows.map(row => [row, row.nextElementSibling?.classList.contains('ea-row-feedback') ? row.nextElementSibling : null]));
        const name = row => row.querySelector('[name="name"]').value;
        rows.sort((a, b) => {
            if (key === 'sort_order') {
                const difference = Number(a.querySelector('[name="sort_order"]').value) - Number(b.querySelector('[name="sort_order"]').value);
                if (difference) return difference;
            }
            return collator.compare(name(a), name(b));
        });
        for (const row of rows) {
            body.append(row);
            if (feedback.get(row)) body.append(feedback.get(row));
        }
        for (const button of buttons) {
            const active = button.dataset.sortKey === key;
            button.closest('th').setAttribute('aria-sort', active ? 'ascending' : 'none');
            button.querySelector('[data-sort-arrow]').textContent = active ? ' ↑' : '';
        }
    };
    for (const button of buttons) button.addEventListener('click', () => sort(button.dataset.sortKey));
    sort('sort_order');
})();
