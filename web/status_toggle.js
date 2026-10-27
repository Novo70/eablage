/* Zeilenaktionen ohne Neuladen; normale Formulare bleiben als Fallback erhalten. */
const rowFeedback = new WeakMap();
document.querySelectorAll('[data-status-toggle], [data-row-action]').forEach((form) => {
    // form.elements includes the save button in the separate Actions column.
    const button = Array.from(form.elements).find((element) => element.type === 'submit');
    const row = form.closest('tr');
    const action = form.dataset.rowAction || 'status';
    const originalLabel = button.textContent;
    let labelTimer;
    const showLabel = (text) => { button.textContent = text; };
    if (action === 'rename') {
        const edited = () => { clearTimeout(labelTimer); showLabel(originalLabel); };
        form.addEventListener('input', edited);
        // The number type sort field lives in its own table cell.
        form.elements.namedItem('sort_order')?.addEventListener('input', edited);
    }
    if (!rowFeedback.has(row)) {
        const feedback = document.createElement('div');
        feedback.setAttribute('role', 'alert');
        feedback.className = 'ea-row-error';
        const errorRow = document.createElement('tr');
        errorRow.className = 'ea-row-feedback';
        errorRow.hidden = true;
        const errorCell = document.createElement('td');
        errorCell.colSpan = row.cells.length;
        errorCell.append(feedback);
        errorRow.append(errorCell);
        row.after(errorRow);
        rowFeedback.set(row, { feedback, errorRow });
    }
    const { feedback, errorRow } = rowFeedback.get(row);
    let pending = false;
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (pending || button.disabled || row.dataset.saving === '1') return;
        const body = new FormData(form);
        if (button.name) body.set(button.name, button.value);
        body.set('status_inline', '1');
        pending = true;
        row.dataset.saving = '1';
        clearTimeout(labelTimer);
        if (action === 'rename') showLabel(originalLabel);
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        errorRow.hidden = true;
        feedback.replaceChildren();
        try {
            const response = await fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST', body, credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            });
            const result = await response.json();
            if (!response.ok || result.ok !== true) {
                const message = document.createElement('div');
                message.textContent = result.message || 'Die Änderung konnte nicht gespeichert werden.';
                feedback.append(message);
                if (Array.isArray(result.rules) && result.rules.length) {
                    const list = document.createElement('ul');
                    result.rules.forEach((rule) => {
                        const item = document.createElement('li');
                        const link = document.createElement('a');
                        const query = new URLSearchParams({
                            regel: String(rule.RegelID),
                            return: 'stammdaten.php?kind=' + body.get('kind')
                        });
                        link.href = 'autokat_regel_bearbeiten.php?' + query.toString();
                        link.textContent = String(rule.RegelID) + ' – ' + (rule.Bezeichnung || '')
                            + (Number(rule.Aktiv) ? '' : ' (inaktive Regel)');
                        item.append(link);
                        list.append(item);
                    });
                    feedback.append(list);
                }
                feedback.className = 'ea-row-error';
                errorRow.hidden = false;
                return;
            }
            if (action === 'rename') {
                // Do not overwrite text edited while the request was in flight.
                const input = form.elements.namedItem('name');
                const explanation = form.elements.namedItem('explanation');
                const sortOrder = form.elements.namedItem('sort_order');
                if (input.value === body.get('name') && (!explanation || explanation.value === body.get('explanation'))
                        && (!sortOrder || sortOrder.value === body.get('sort_order'))) {
                    const summary = form.querySelector('.ea-master-explanation summary');
                    if (summary && explanation) summary.textContent = explanation.value.trim() ? 'Erläuterung (vorhanden)' : 'Erläuterung (optional)';
                    showLabel('✓ Gespeichert');
                    labelTimer = setTimeout(() => showLabel(originalLabel), 2000);
                }
                return;
            }
            if (action === 'delete') {
                // Keep a placeholder so later rows and the scroll position do not jump.
                const height = row.getBoundingClientRect().height;
                const cell = document.createElement('td');
                cell.colSpan = row.cells.length;
                cell.style.height = height + 'px';
                cell.textContent = '✓ Eintrag gelöscht';
                row.replaceChildren(cell);
                row.classList.add('ea-row-deleted');
                errorRow.remove();
                return;
            }
            if (typeof result.active !== 'boolean') throw new Error('Invalid status');
            const active = result.active;
            const isRule = form.dataset.statusToggle === 'rule';
            button.textContent = active ? '✅ aktiv' : '⏸️ inaktiv';
            const label = isRule
                ? (active ? 'Regel deaktivieren' : 'Regel aktivieren')
                : (active ? 'Eintrag deaktivieren' : 'Eintrag reaktivieren');
            button.title = label;
            button.setAttribute('aria-label', label);
            if (isRule) {
                form.elements.namedItem('aktiv').value = active ? '0' : '1';
                form.closest('tr').classList.toggle('ea-rule-inactive', !active);
            } else {
                button.value = active ? 'deactivate' : 'activate';
            }
        } catch (error) {
            feedback.textContent = 'Speicherung nicht bestätigt. Bitte die Ansicht neu laden und den aktuellen Status prüfen.';
            feedback.className = 'ea-row-error';
            errorRow.hidden = false;
        } finally {
            pending = false;
            delete row.dataset.saving;
            button.disabled = form.dataset.statusToggle === 'master'
                && form.dataset.hasRules === '1' && button.value === 'deactivate';
            if (button.disabled) {
                button.title = 'Deaktivieren nicht möglich: AutoKat-Regeln sind verknüpft.';
            }
            button.removeAttribute('aria-busy');
        }
    });
});

