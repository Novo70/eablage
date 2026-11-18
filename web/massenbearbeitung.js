(() => {
    const form = document.getElementById('mass-form');
    const preview = document.getElementById('mass-preview');
    const save = document.getElementById('mass-save');
    let running = false;
    form.querySelectorAll('[data-mass-field]').forEach(field => {
        const mode = field.querySelector('[data-mode]');
        function update() {
            const inputs = field.querySelector('[data-inputs]');
            inputs.hidden = !mode.value || mode.value === 'clear';
            field.querySelectorAll('[data-for]').forEach(part => { part.hidden = part.dataset.for !== mode.value; });
        }
        mode.addEventListener('change', update);
        update();
    });
    form.addEventListener('input', () => {
        if (save) save.disabled = true;
        if (preview) document.getElementById('mass-progress').textContent = 'Eingaben geändert. Bitte eine neue Vorschau erstellen.';
    });
    window.addEventListener('beforeunload', event => { if (running) event.preventDefault(); });
    save?.addEventListener('click', async () => {
        if (running) return;
        running = true;
        save.disabled = true;
        form.querySelectorAll('input,select,button').forEach(control => { control.disabled = true; });
        const rows = [...preview.querySelectorAll('[data-ready]')];
        const progress = document.getElementById('mass-progress');
        let completed = 0;
        for (const row of rows) {
            progress.textContent = 'Verarbeite Dokument ' + (++completed) + ' von ' + rows.length;
            try {
                const data = new URLSearchParams({action:'apply', batch:preview.dataset.batch, id:row.dataset.result, aktion_token:preview.dataset.token});
                const response = await fetch('massenbearbeitung.php', {method:'POST', body:data});
                const result = await response.json();
                if (!response.ok || typeof result.result !== 'string') throw new Error(result.error || 'Speichern nicht bestätigt.');
                row.textContent = result.result;
                const card = document.querySelector('[data-current-document="' + row.dataset.result + '"]');
                if (card && result.result === 'Gespeichert') {
                    const status = card.querySelector('[data-current-status]');
                    if (result.metadata) {
                        card.querySelectorAll('[data-current-field]').forEach(cell => {
                            cell.textContent = result.metadata[cell.dataset.currentField] || '—';
                        });
                    }
                    status.textContent = result.metadata ? 'Gespeicherter Stand aktualisiert.' : 'Gespeichert. Metadatenanzeige bitte neu laden.';
                    status.hidden = false;
                }
                delete row.dataset.ready;
            } catch {
                row.textContent = 'Speichern nicht bestätigt. Erneut auf Änderungen speichern klicken, um den Status abzufragen.';
                break;
            }
        }
        running = false;
        save.disabled = !preview.querySelector('[data-ready]');
        form.querySelectorAll('input,select,button').forEach(control => { control.disabled = false; });
        progress.textContent = save.disabled ? 'Verarbeitung abgeschlossen. Bitte Ergebnisse prüfen.' : 'Verarbeitung unterbrochen. Erneuter Aufruf möglich.';
    });
})();

