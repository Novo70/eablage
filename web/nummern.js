/* One value per number type; the JSON field also preserves tab-local drafts. */
(() => {
    const root = document.getElementById('number-editor');
    if (!root) return;
    const hidden = root.querySelector('#numbers_json');
    const legacy = root.querySelector('#Nummer');
    const kinds = JSON.parse(document.getElementById('number-kinds').textContent);
    const rows = root.querySelector('[data-number-rows]');
    const touched = new Set();
    const proposals = new Map();
    // The kind list uses the database's Sortierung, Bezeichnung, id order.
    const kindOrder = new Map(kinds.map((kind, index) => [String(kind.id), index]));
    let nextFieldId = 0;
    function sortRows() {
        const current = [...rows.children];
        const sorted = [...current].sort((a, b) =>
            (kindOrder.get(a.querySelector('select').value) ?? Infinity) -
            (kindOrder.get(b.querySelector('select').value) ?? Infinity));
        if (sorted.every((row, index) => row === current[index])) return;
        const active = document.activeElement;
        const focused = rows.contains(active);
        const selection = focused && active.tagName === 'INPUT' && active.type === 'text'
            ? [active.selectionStart, active.selectionEnd, active.selectionDirection] : null;
        rows.append(...sorted);
        if (focused) {
            active.focus({preventScroll: true});
            if (selection) active.setSelectionRange(...selection);
        }
    }
    function values() {
        return [...rows.children].map(row => ({kind_id: row.querySelector('select').value,
            value: row.querySelector('input').value})).filter(n => n.kind_id && n.value.trim());
    }
    function sync() {
        sortRows();
        for (const row of rows.children) {
            const select = row.querySelector('select');
            select.setCustomValidity(select.value && [...rows.children].some(other => other !== row && other.querySelector('select').value === select.value) ? 'Diese Nummernart ist bereits vorhanden.' : '');
        }
        hidden.value = JSON.stringify(values());
        legacy.value = (values()[0]?.value || '').slice(0, 255);
        hidden.dispatchEvent(new Event('input', {bubbles: true}));
    }
    function sourceBadge(source) {
        if (source?.type === 'textcompare') {
            const id = String(source.source_document_id ?? '').trim();
            if (/^\d+$/.test(id)) {
                const badge = document.createElement('a');
                badge.className = 'ea-source-badge ea-source-badge--date';
                badge.style.cursor = 'pointer';
                badge.textContent = 'DokID ' + id;
                badge.title = 'Textvergleich mit DokID ' + id;
                if (source.similarity != null && source.similarity !== '') badge.title += ' (' + source.similarity + ' % Übereinstimmung)';
                badge.title += ' – Vergleichsdokument öffnen';
                const back = location.pathname.split('/').pop() + location.search;
                badge.href = 'bearbeiten.php?' + new URLSearchParams({dokid: id, return: back});
                badge.setAttribute('data-draft-link', '');
                return badge;
            }
        }
        const valid = source && ['E', 'K'].includes(source.type);
        const rule = valid ? String(source.regel_id || '').trim() : '';
        const badge = document.createElement(rule ? 'a' : 'span');
        badge.className = 'ea-source-badge';
        if (!valid) return badge;
        badge.classList.add(source.type === 'K' ? 'ea-source-badge--classification' : 'ea-source-badge--supplement');
        badge.textContent = rule || String(source.rank ?? source.type);
        let title = source.type === 'K' ? 'Klassifikationsregel' : 'Ergänzungsregel';
        if (rule) title += ' ' + rule + (source.version != null && source.version !== '' ? '/v' + source.version : '');
        if (source.rank != null && source.rank !== '') title += ' (Rang ' + source.rank + ')';
        if (source.label) title += ' – ' + source.label;
        if (['extract', 'number_anchor'].includes(source.method)) title += ' (aus Dokumenttext extrahiert)';
        badge.title = title;
        if (rule) {
            const back = location.pathname.split('/').pop() + location.search;
            badge.href = 'autokat_regel_bearbeiten.php?' + new URLSearchParams({regel: rule, return: back});
            badge.setAttribute('data-draft-link', '');
        }
        return badge;
    }
    function add(item = {}) {
        const row = document.createElement('div'); row.className = 'form-group ea-number-row';
        const select = document.createElement('select'); select.className = 'form-control'; select.setAttribute('aria-label', 'Nummernart');
        select.add(new Option('Nummernart wählen', ''));
        for (const kind of kinds) {
            if (!Number(kind.Aktiv) && String(kind.id) !== item.kind_id) continue;
            const option = new Option(kind.Bezeichnung + (!Number(kind.Aktiv) ? ' (inaktiv)' : ''), String(kind.id));
            option.title = kind.Erlaeuterung || ''; select.add(option);
        }
        select.value = item.kind_id || '';
        const input = document.createElement('input'); input.className = 'form-control'; input.type = 'text'; input.maxLength = 255;
        input.setAttribute('aria-label', 'Nummer'); input.value = item.value || '';
        input.id = 'document-number-' + (++nextFieldId);
        let badge = sourceBadge(item.source);
        const replaceBadge = next => { window.eablageAiBadges?.dismiss(badge); badge.replaceWith(next); badge = next; };
        const feedback = document.createElement('div'); feedback.className = 'ea-ollama-field ea-number-ai'; feedback.hidden = true;
        const confirmed = document.createElement('span'); confirmed.className = 'ea-source-badge ea-source-badge--ai ea-ollama-confirmed'; confirmed.textContent = 'KI bestätigt'; confirmed.hidden = true;
        const badges = document.createElement('div'); badges.className = 'ea-number-badges';
        badges.append(badge, confirmed);
        const proposal = document.createElement('button'); proposal.type = 'button'; proposal.className = 'ea-ollama-apply'; proposal.hidden = true;
        const refresh = () => {
            const p = proposals.get(select.value);
            const same = p && p.value === input.value;
            proposal.hidden = !p || same;
            confirmed.hidden = !same || badge.classList.contains('ea-source-badge--ai');
            badges.hidden = !badge.textContent && confirmed.hidden;
            input.setAttribute('aria-label', select.value ? select.selectedOptions[0].textContent : 'Nummer');
            feedback.hidden = !p || same;
            window.eablageAiBadges?.decorate(confirmed, p?.model || '', p?.evidence || '');
            if (confirmed.hidden) window.eablageAiBadges?.dismiss(confirmed);
            proposal.title = p?.evidence || '';
            proposal.textContent = p ? 'KI: ' + p.value + ' → Übernehmen' : '';
        };
        const adopt = () => {
            const p = proposals.get(select.value); if (!p) return;
            input.value = p.value;
            replaceBadge(sourceBadge());
            const marker = 'KI (Ollama: ' + p.model + ')';
            badge.textContent = 'KI'; badge.className = 'ea-source-badge ea-source-badge--ai';
            window.eablageAiBadges?.decorate(badge, p.model, p.evidence || '');
            sync(); refresh();
            const info = document.querySelector('[name="erkennungs_info"]');
            if (info && !info.value.includes(marker)) info.value += (info.value ? ' | ' : '') + marker;
        };
        proposal.addEventListener('click', adopt);
        const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-default btn-sm ea-number-remove'; remove.textContent = 'Entfernen';
        remove.addEventListener('click', () => { touched.add(select.value); window.eablageAiBadges?.dismiss(row); row.remove(); sync(); });
        select.addEventListener('change', () => {
            if ([...rows.children].some(other => other !== row && other.querySelector('select').value === select.value)) {
                select.setCustomValidity('Diese Nummernart ist bereits vorhanden.');
            } else select.setCustomValidity('');
            touched.add(select.value); replaceBadge(sourceBadge()); sync(); refresh();
        });
        input.addEventListener('input', () => { touched.add(select.value); replaceBadge(sourceBadge()); sync(); refresh(); });
        feedback.append(proposal);
        row.append(select, input, badges, remove, feedback); rows.append(row); sortRows(); refresh();
        row.refreshProposal = refresh; row.adoptProposal = adopt;
        return row;
    }
    function restore() {
        window.eablageAiBadges?.dismiss(rows);
        rows.replaceChildren();
        try { for (const n of JSON.parse(hidden.value)) add(n); } catch (_) {}
        legacy.value = (values()[0]?.value || '').slice(0, 255);
    }
    root.querySelector('[data-number-add]').addEventListener('click', () => add().querySelector('select').focus());
    hidden.addEventListener('change', restore);
    restore();
    window.eablageNumbers = {
        values,
        suggest(numbers, model, background) {
            proposals.clear();
            for (const n of numbers || []) {
                if (!kinds.some(k => String(k.id) === n.kind_id && Number(k.Aktiv))) continue;
                proposals.set(n.kind_id, {...n, model});
                let row = [...rows.children].find(r => r.querySelector('select').value === n.kind_id);
                if (!row) row = add({kind_id: n.kind_id});
                row.refreshProposal();
                if (!row.querySelector('input').value.trim() && (!background || (!touched.has(n.kind_id) && document.querySelector('form[data-classification-draft]')?.dataset.classificationDraftRestored !== 'true'))) row.adoptProposal();
            }
            for (const row of rows.children) row.refreshProposal();
        }
    };
})();
