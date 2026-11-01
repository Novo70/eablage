(() => {
    const root = document.querySelector('[data-vorgaenge-editor]');
    if (!root) return;
    const form = root.closest('form');
    const input = root.querySelector('[data-vorgaenge-value]');
    const select = root.querySelector('[data-vorgaenge-select]');
    const rows = root.querySelector('[data-vorgaenge-rows]');
    const error = root.querySelector('[data-vorgaenge-error]');
    const labels = new Map(Array.from(select.options, o => [Number(o.value), o.textContent]));
    let ids = [];
    function render() {
        rows.replaceChildren();
        for (const id of ids) {
            const row = document.createElement('div');
            row.className = 'ea-vorgang-row';
            const label = document.createElement('a');
            label.href = 'vorgaenge.php?id=' + encodeURIComponent(id);
            label.setAttribute('data-draft-link', '');
            if (window.eablageBindDraftLink) window.eablageBindDraftLink(label);
            label.textContent = labels.get(id) || 'Vorgang ' + id + ' (bitte prüfen)';
            const remove = document.createElement('button');
            remove.type = 'button'; remove.className = 'btn btn-default btn-sm';
            remove.textContent = 'Entfernen';
            remove.setAttribute('aria-label', label.textContent + ': Zuordnung entfernen');
            remove.addEventListener('click', () => { ids = ids.filter(x => x !== id); sync(); });
            row.append(label, remove); rows.append(row);
        }
        for (const option of select.options) option.hidden = ids.includes(Number(option.value));
        select.value = '';
    }
    function sync() {
        ids.sort((a, b) => a - b);
        input.value = JSON.stringify(ids);
        input.dispatchEvent(new Event('input', {bubbles: true}));
        render();
    }
    function restore() {
        try { ids = JSON.parse(input.value); if (!Array.isArray(ids)) throw new Error(); render(); }
        catch (_) { error.textContent = 'Die Vorgangsauswahl konnte nicht geladen werden. Bitte die Seite neu öffnen.'; error.hidden = false; }
    }
    input.addEventListener('change', restore);
    root.querySelector('[data-vorgaenge-add]').addEventListener('click', () => {
        const id = Number(select.value);
        if (id > 0 && !ids.includes(id)) { ids.push(id); sync(); }
    });
    root.querySelector('[data-vorgaenge-create]').addEventListener('click', async event => {
        const button = event.currentTarget;
        const name = root.querySelector('[data-vorgaenge-name]');
        const description = root.querySelector('[data-vorgaenge-description]');
        error.hidden = true;
        if (!name.value.trim()) { name.focus(); return; }
        button.disabled = true;
        const submits = Array.from(form.querySelectorAll('button[type="submit"], input[type="submit"]')).filter(x => !x.disabled);
        submits.forEach(x => x.disabled = true);
        try {
            const body = new FormData();
            body.set('aktion_token', form.elements.namedItem('aktion_token').value);
            body.set('name', name.value); body.set('description', description.value);
            const response = await fetch('vorgang_anlegen.php', {method: 'POST', body, headers: {'Accept': 'application/json'}});
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || 'Vorgang konnte nicht angelegt werden.');
            labels.set(result.id, result.name);
            select.add(new Option(result.name, String(result.id)));
            ids.push(result.id); sync(); name.value = ''; description.value = '';
            root.querySelector('details').open = false;
        } catch (e) { error.textContent = e.message; error.hidden = false; }
        finally { button.disabled = false; submits.forEach(x => x.disabled = false); }
    });
    restore();
})();
