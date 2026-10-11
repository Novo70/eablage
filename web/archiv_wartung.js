(function () {
    'use strict';

    let busy = false;

    async function request(form, action, selections) {
        const body = new URLSearchParams({
            action,
            aktion_token: form.elements.aktion_token.value
        });
        if (selections) body.set('selection_json', JSON.stringify(selections));
        // A control named action shadows HTMLFormElement.action in native browsers.
        const response = await fetch(form.getAttribute('action'), { method: 'POST', body });
        let data;
        try {
            data = await response.json();
        } catch (_) {
            throw new Error('Keine gültige Antwort erhalten.');
        }
        if (!response.ok) throw new Error(data.detail || 'Verarbeitung fehlgeschlagen.');
        return data;
    }

    function addResult(list, text) {
        const item = document.createElement('li');
        item.textContent = text;
        list.appendChild(item);
    }

    async function run(form, progress, work) {
        if (busy) return;
        busy = true;
        const controls = Array.from(document.querySelectorAll(
            '#archivpruefung input, #archivpruefung button'
        ));
        const disabled = controls.map(control => control.disabled);
        controls.forEach(control => { control.disabled = true; });
        try {
            await work();
        } catch (error) {
            progress.textContent += ' Verarbeitung gestoppt: ' + error.message +
                ' Bereits abgeschlossene Änderungen bleiben erhalten. Archiv prüfen und Vorschau aktualisieren, bevor Sie erneut starten.';
        } finally {
            controls.forEach((control, index) => { control.disabled = disabled[index]; });
            // The rendered report/preview is now stale, even after a network error.
            form.querySelectorAll('button').forEach(button => { button.disabled = true; });
            busy = false;
        }
    }

    for (const repairForm of [document.getElementById('archive-repair-form')].filter(Boolean)) {
        const checkboxes = Array.from(repairForm.querySelectorAll('input[name="archive_selection[]"]'));
        const count = repairForm.querySelector('[data-archive-count]');
        const progress = repairForm.querySelector('[data-archive-progress]');
        const results = repairForm.querySelector('[data-archive-results]');
        const updateCount = () => {
            if (count) count.textContent = checkboxes.filter(box => box.checked).length + ' ausgewählt';
        };
        repairForm.querySelectorAll('.archive-bulk-controls').forEach(control => { control.hidden = false; });
        repairForm.querySelectorAll('[data-archive-select]').forEach(button => {
            button.addEventListener('click', () => {
                checkboxes.forEach(box => { box.checked = button.dataset.archiveSelect === 'all'; });
                updateCount();
            });
        });
        checkboxes.forEach(box => box.addEventListener('change', updateCount));
        updateCount();
        // Replace the no-JavaScript confirmation with one confirmation per complete selection.
        repairForm.onsubmit = null;
        repairForm.addEventListener('submit', event => {
            event.preventDefault();
            if (busy) return;
            const selected = checkboxes.filter(box => box.checked).map(box => JSON.parse(box.value));
            if (!selected.length) {
                progress.textContent = 'Bitte mindestens eine Metadatei auswählen.';
                return;
            }
            if (!window.confirm(selected.length + ' ausgewählte JSON-Metadateien aus der Datenbank neu erzeugen?')) return;
            results.textContent = '';
            let processed = 0;
            let successful = 0;
            progress.textContent = '0 von ' + selected.length + ' Dateien verarbeitet.';
            run(repairForm, progress, async () => {
                for (let offset = 0; offset < selected.length; offset += 100) {
                    const batch = selected.slice(offset, offset + 100);
                    const data = await request(repairForm, 'archive_repair_batch', batch);
                    if (!Array.isArray(data.results) || data.results.length > batch.length || (data.results.length !== batch.length && !data.results.some(item => !item.success))) {
                        throw new Error('Unvollständiges Paketergebnis.');
                    }

                    processed += data.results.length;
                    successful += data.results.filter(item => item.success).length;
                    data.results.filter(item => !item.success).forEach(item => {
                        addResult(results, 'DokID ' + item.dok_id + ': ' + item.message);
                    });
                    progress.textContent = processed + ' von ' + selected.length + ' Dateien verarbeitet; ' + successful + ' erfolgreich.';
                    if (data.results.some(item => !item.success)) throw new Error('Mindestens eine Metadatei konnte nicht repariert werden.');
                }
                progress.textContent += ' Fertig. Bitte Archiv erneut prüfen.';
            });
        });
    }

}());
