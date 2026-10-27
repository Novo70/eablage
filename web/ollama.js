/* Manually requested proposals remain editable until the document is saved. */
(() => {
    const form = document.getElementById('classification-document-form');
    const button = document.getElementById('ollama-suggest');
    const testButton = document.getElementById('ollama-check');
    const fieldMap = {date: ['Datum', 'Datum'], partner: ['Partner', 'Partner / Absender'],
        subject: ['Betreff', 'Betreff'], category: ['Kategorie', 'Kategorie'],
        document_type: ['Doktyp', 'Dokumententyp'], tags: ['Tags', 'Stichworte / Tags']};
    if (!window.eablageNumbers) fieldMap.number = ['Nummer', 'Nummer'];
    async function request(data, timeout) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeout);
        try {
            const response = await fetch('ollama.php', {method: 'POST', body: new URLSearchParams(data), signal: controller.signal});
            let result;
            try { result = await response.json(); }
            catch (_) { throw new Error('Die KI-Anfrage hat keine gültige Antwort geliefert.'); }
            const backgroundFailure = data.action === 'background' && result.status === 'fehler';
            if (!response.ok || (result.error && !backgroundFailure)) {
                const error = new Error(result.error || 'Die KI-Anfrage ist fehlgeschlagen.');
                error.diagnostic = result.diagnostic;
                throw error;
            }
            return result;
        } catch (error) {
            if (error.name === 'AbortError') throw new Error('Zeitlimit überschritten. Bitte PC und Ollama prüfen.');
            throw error;
        } finally { clearTimeout(timer); }
    }
    if (testButton) {
        testButton.addEventListener('click', async () => {
            const status = document.getElementById('ollama-check-status');
            testButton.disabled = true;
            status.textContent = 'Verbindung wird geprüft …';
            try {
                const settings = document.getElementById('settings-form');
                const result = await request({action: 'check', aktion_token: settings.elements.namedItem('aktion_token').value}, 12000);
                status.textContent = result.message + ' Modell: ' + result.model;
            } catch (error) { status.textContent = error.message; }
            finally { testButton.disabled = false; }
        });
    }
    if (!form || !button) return;
    const status = document.getElementById('ollama-status');
    const recognitionDetails = document.getElementById('classification-recognition-details');
    const recognitionSection = document.getElementById('ollama-recognition-details');
    const recognitionNotes = document.getElementById('ollama-recognition-notes');
    const panel = document.getElementById('ollama-panel');
    const menu = document.getElementById('classification-more-actions');
    const diagnosticButton = document.getElementById('ollama-diagnostic-open');
    const diagnosticPanel = document.getElementById('ollama-diagnostic-panel');
    const diagnosticStatus = document.getElementById('ollama-diagnostic-status');
    const diagnosticContent = document.getElementById('ollama-diagnostic-content');
    const diagnosticJson = document.getElementById('ollama-diagnostic-json');
    const diagnosticDetails = document.getElementById('ollama-diagnostic-json-details');
    const diagnosticCopy = document.getElementById('ollama-diagnostic-copy');
    function showDiagnostic(diagnostic) {
        if (!diagnosticPanel) return;
        diagnosticContent.replaceChildren();
        diagnosticJson.value = '';
        diagnosticCopy.disabled = true;
        diagnosticCopy.textContent = 'JSON kopieren';
        diagnosticDetails.hidden = true;
        if (!diagnostic?.request) {
            diagnosticStatus.textContent = 'Noch keine Anfrage erfasst. Bitte „KI-Vorschlag erstellen“ aufrufen und anschließend hier prüfen.';
            return;
        }
        const outgoing = diagnostic.request;
        let input = {};
        try { input = JSON.parse(outgoing.messages.find(message => message.role === 'user').content); }
        catch (_) { /* The original request remains available even for unexpected structures. */ }
        const addSection = (title, value, open = false) => {
            const details = document.createElement('details'); details.open = open;
            const summary = document.createElement('summary'); summary.textContent = title;
            const text = document.createElement('pre'); text.textContent = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
            details.append(summary, text); diagnosticContent.append(details);
        };
        diagnosticStatus.textContent = 'Letzte Anfrage an ' + diagnostic.url + '. ' +
            (diagnostic.response_received ? 'Ollama-Antwort empfangen.' : 'Versand oder Antwort nicht bestätigt.') +
            (diagnostic.truncated ? ' Dokumenttext wurde gekürzt.' : ' Dokumenttext wurde nicht gekürzt.');
        const metrics = diagnostic.metrics || {};
        const number = value => Number.isSafeInteger(value) && value >= 0;
        const format = (value, digits = 0) => value.toLocaleString('de-DE', {minimumFractionDigits: digits, maximumFractionDigits: digits});
        const seconds = key => number(metrics[key]) ? format(metrics[key] / 1e9, 3) + ' s' : '—';
        const tokens = key => number(metrics[key]) ? format(metrics[key]) : '—';
        const measurement = document.createElement('details'); measurement.open = true;
        const heading = document.createElement('summary'); heading.textContent = 'Laufzeiten und Token-Zahlen';
        const table = document.createElement('table'); table.className = 'table';
        const rows = [
            ['Gesamtzeit (Ollama)', seconds('total_duration')],
            ['Modell laden', seconds('load_duration')],
            ['Eingabe verarbeiten', seconds('prompt_eval_duration')],
            ['Generierung (Denken und Antwort)', seconds('eval_duration')],
            ['Eingabetokens', tokens('prompt_eval_count')],
            ['Eingabetokens aus dem Cache', tokens('prompt_eval_cached_count')],
            ['Erzeugte Tokens', tokens('eval_count')],
            ['Generierungsgeschwindigkeit', number(metrics.eval_count) && number(metrics.eval_duration) && metrics.eval_duration > 0
                ? format(metrics.eval_count / (metrics.eval_duration / 1e9), 1) + ' Tokens/s' : '—'],
            ['Abschluss', diagnostic.done_reason || '—']
        ];
        for (const [label, value] of rows) {
            const row = document.createElement('tr');
            const name = document.createElement('th'); name.scope = 'row'; name.textContent = label;
            const cell = document.createElement('td'); cell.textContent = value;
            row.append(name, cell); table.append(row);
        }
        const explanation = document.createElement('p');
        explanation.textContent = 'Messwerte stammen von Ollama. Generierung umfasst bei aktivem Denkmodus Denken und Antwort; deren Zeiten werden nicht getrennt ausgewiesen. Fehlende Werte erscheinen als —. Die Gesamtzeit ist keine Messung der gesamten Wartezeit im Browser.';
        measurement.append(heading, table, explanation); diagnosticContent.append(measurement);
        if (diagnostic.attempts) addSection('KI-Durchläufe (Denken und Rückfall)', diagnostic.attempts, true);
        if (diagnostic.text_selection) addSection('Textauswahl (Zeichen)', diagnostic.text_selection, true);
        addSection('Dokumenttext wie gesendet', input.document_text || '', true);
        addSection('Fachliche KI-Anweisung', input.classification_guidance || '');
        addSection('Kategorien mit Erläuterungen', input.categories || {});
        addSection('Dokumententypen mit Erläuterungen', input.document_types || {});
        addSection('Nummernarten mit Erläuterungen', input.number_types || {});
        addSection('Formularwerte vor der KI-Anfrage', input.current_fields || {});
        addSection('Feste Systemanweisung', outgoing.messages.find(message => message.role === 'system')?.content || '');
        const {messages, ...technical} = outgoing;
        addSection('Modell, Denkmodus und technische Einstellungen', technical);
        diagnosticJson.value = diagnostic.body || JSON.stringify(outgoing, null, 2);
        diagnosticCopy.disabled = false;
        diagnosticDetails.hidden = false;
    }
    if (diagnosticButton) {
        diagnosticButton.addEventListener('click', () => {
            if (menu) menu.open = false;
            if (recognitionDetails) recognitionDetails.open = true;
            diagnosticPanel.hidden = false;
            diagnosticButton.setAttribute('aria-expanded', 'true');
            document.getElementById('ollama-diagnostic-close').focus();
        });
        document.getElementById('ollama-diagnostic-close').addEventListener('click', () => {
            diagnosticPanel.hidden = true;
            diagnosticButton.setAttribute('aria-expanded', 'false');
            if (menu) menu.querySelector('summary').focus();
        });
        diagnosticCopy.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(diagnosticJson.value);
                diagnosticCopy.textContent = '✓ Kopiert';
            } catch (_) {
                diagnosticDetails.open = true;
                diagnosticJson.focus(); diagnosticJson.select();
                diagnosticCopy.textContent = 'Text markiert – mit Strg+C kopieren';
            }
        });
    }
    const suggestions = [];
    function clearSuggestions() {
        for (const {row, target, refresh, confirmed} of suggestions) {
            window.eablageAiBadges?.dismiss(confirmed);
            confirmed.remove();
            row.remove();
            target.removeEventListener('input', refresh);
            target.removeEventListener('change', refresh);
        }
        suggestions.length = 0;
    }
    const field = name => form.elements.namedItem(name);
    const empty = target => target.tagName === 'SELECT' ? !target.value || target.value === '0' : !target.value.trim();
    function cleanTags(value) {
        const words = text => new Set((text.normalize('NFKC').toLowerCase().replace(/ß/g, 'ss').match(/[\p{L}\p{N}]+/gu) || []));
        const metadata = [];
        for (const name of ['Datum', 'Partner', 'Betreff', 'Kategorie', 'Kategorie2', 'Doktyp', 'Nummer']) {
            const target = field(name);
            if (!target) continue;
            metadata.push(target.tagName === 'SELECT' ? (target.selectedOptions[0]?.textContent || '') : target.value);
        }
        const covered = words(metadata.join(' '));
        const generic = new Set(['dokument', 'schreiben', 'unterlagen', 'information', 'informationen']);
        const seen = new Set();
        return value.split(/[,;]/).map(tag => tag.trim().replace(/\s+/g, ' ')).filter(tag => {
            const tokens = [...words(tag)];
            const identity = tag.normalize('NFKC').toLowerCase().replace(/ß/g, 'ss');
            if (!tokens.length || tokens.every(t => covered.has(t)) || tokens.every(t => generic.has(t)) || seen.has(identity)) return false;
            seen.add(identity); return true;
        }).slice(0, 5).join(', ');
    }
    const touched = new Set(form.dataset.classificationDraftRestored === 'true'
        ? Object.values(fieldMap).map(([name]) => name) : []);
    let backgroundCancelled = false;
    for (const type of ['input', 'change']) form.addEventListener(type, event => { touched.add(event.target.name); });
    function clearRecognitionNotes() {
        if (recognitionNotes) recognitionNotes.replaceChildren();
        if (recognitionSection) recognitionSection.hidden = true;
    }
    function showRecognitionNotes({history, warnings = [], message = '', error = '', lastAttempt = null}) {
        if (!recognitionNotes || !recognitionSection) return;
        recognitionNotes.replaceChildren();
        recognitionSection.hidden = false;
        const alert = (tone, title) => {
            const block = document.createElement('div');
            block.className = 'alert alert-' + tone + ' ea-recognition-note';
            const heading = document.createElement('strong'); heading.textContent = title;
            block.append(heading); recognitionNotes.append(block);
            return block;
        };
        const information = message || history ? alert('info', 'ℹ️ KI-Erkennung') : null;
        if (message && information) {
            const note = document.createElement('p'); note.textContent = message;
            information.append(note);
        }
        if (history) {
            const block = document.createElement('div'); block.dataset.aiHistory = 'true';
            const heading = document.createElement('strong'); heading.textContent = 'KI-Verlauf';
            const summary = document.createElement('p'); summary.textContent = history.summary;
            const list = document.createElement('ul');
            for (const text of history.lines || []) {
                const line = document.createElement('li'); line.textContent = text; list.append(line);
            }
            block.append(heading, summary, list); information.append(block);
        }
        if (warnings.length) {
            const block = alert('warning', '⚠️ KI-Hinweise'); block.dataset.aiWarnings = 'true';
            for (const warning of warnings) {
                const note = document.createElement('p'); note.textContent = warning; block.append(note);
            }
        }
        if (error) {
            const block = alert('warning', '⚠️ KI-Fehler'); block.dataset.backgroundError = 'true';
            const note = document.createElement('p'); note.textContent = error;
            block.append(note);
            if (lastAttempt) {
                const attempt = document.createElement('p');
                attempt.textContent = 'Letzter Versuch: ' + new Date(lastAttempt * 1000).toLocaleString('de-DE');
                block.append(attempt);
            }
        }
    }
    function showStatus(message) {
        status.textContent = message;
        panel.hidden = !message;
    }
    function showFailure(message) {
        panel.hidden = false;
        status.replaceChildren(document.createTextNode(message + ' '));
        if (!recognitionDetails) return;
        const link = document.createElement('a'); link.href = '#classification-recognition-details';
        link.textContent = 'Erkennungsdetails';
        link.addEventListener('click', event => {
            event.preventDefault(); recognitionDetails.open = true;
            recognitionDetails.scrollIntoView({block: 'start'});
            recognitionDetails.querySelector('summary')?.focus({preventScroll: true});
        });
        status.append(link);
    }
    const unknownHistory = {summary: 'Keine Durchlaufhistorie vorhanden; ältere Versuche lassen sich nicht nachträglich rekonstruieren.', lines: []};
    function render(result, background) {
        showDiagnostic(result.diagnostic);
        showRecognitionNotes({message: 'Modell: ' + result.model, history: result.attempt_history || unknownHistory, warnings: result.warnings || []});
        showStatus('');
        window.eablageNumbers?.suggest(result.proposal.numbers, result.model, background);
        let count = result.proposal.numbers?.length || 0;
        for (const [key, [name, label]] of Object.entries(fieldMap)) {
            const value = key === 'tags' ? cleanTags(result.proposal[key] || '') : result.proposal[key];
            if (!value) continue;
            count++;
            const target = field(name);
            if (!target) continue;
            const row = document.createElement('div'); row.className = 'ea-ollama-field';
            row.dataset.field = name;
            const apply = document.createElement('button');
            apply.type = 'button'; apply.className = 'ea-ollama-apply';
            const displayValue = result.labels[key] || (key === 'date' ? value.split('-').reverse().join('.') : value);
            apply.textContent = 'KI: ' + displayValue + ' → Übernehmen';
            apply.setAttribute('aria-label', label + ': KI-Vorschlag „' + displayValue + '“ übernehmen');
            const confirmed = document.createElement('span');
            confirmed.className = 'ea-source-badge ea-source-badge--ai ea-ollama-confirmed'; confirmed.textContent = 'KI bestätigt';
            const evidence = result.proposal[key + '_evidence'];
            const fieldLabel = document.querySelector('label[for="' + name + '"]');
            const existingAi = fieldLabel?.querySelector('.ea-source-badge--ai:not(.ea-ollama-confirmed)');
            let adopted = existingAi?.dataset.aiValue === value;
            let adoptedBadge = adopted ? existingAi : null;
            if (fieldLabel) fieldLabel.append(confirmed); else target.insertAdjacentElement('beforebegin', confirmed);
            window.eablageAiBadges?.decorate(confirmed, result.model, evidence);
            const refresh = () => {
                const same = target.value === value;
                row.hidden = same;
                apply.hidden = same; confirmed.hidden = !same || adopted;
                if (confirmed.hidden) window.eablageAiBadges?.dismiss(confirmed);
                if (adoptedBadge) {
                    adoptedBadge.hidden = !same;
                    if (!same) window.eablageAiBadges?.dismiss(adoptedBadge);
                }
            };
            target.addEventListener('input', refresh);
            target.addEventListener('change', refresh);
            refresh();
            row.append(apply);
            suggestions.push({row, target, refresh, confirmed});
            const adopt = automatic => {
                if (target.tagName === 'SELECT' && !Array.from(target.options).some(option => option.value === value)) {
                    showStatus('Die vorgeschlagene Zuordnung ist nicht verfügbar. Bitte Seite neu öffnen.'); return;
                }
                adopted = true;
                target.value = value;
                target.dispatchEvent(new Event('input', {bubbles: true}));
                target.dispatchEvent(new Event('change', {bubbles: true}));
                const info = field('erkennungs_info');
                const marker = 'KI (Ollama: ' + result.model + ')';
                if (!info.value.includes(marker)) info.value += (info.value ? ' | ' : '') + marker;
                // Rule badges no longer describe this field after manual proposal adoption.
                if (fieldLabel) {
                    fieldLabel.querySelectorAll('.ea-source-badge:not(.ea-ollama-confirmed)').forEach(badge => { window.eablageAiBadges?.dismiss(badge); badge.remove(); });
                    const badge = document.createElement('span'); badge.className = 'ea-source-badge ea-source-badge--ai';
                    badge.textContent = 'KI'; badge.dataset.aiValue = value;
                    window.eablageAiBadges?.decorate(badge, result.model, evidence);
                    fieldLabel.insertBefore(badge, confirmed); adoptedBadge = badge;
                }
                if (!automatic) target.focus();
                // Retain the proposal so later edits can be reverted without another request.
                refresh();
            };
            target.insertAdjacentElement('afterend', row);
            apply.addEventListener('click', () => adopt(false));
            if (empty(target) && (!background || !touched.has(name))) adopt(true);
        }
        if (!count) showStatus('Die KI konnte keine verwertbaren Angaben ermitteln.');
    }
    let busy = false;
    button.addEventListener('click', async () => {
        if (busy) return;
        backgroundCancelled = true;
        busy = true;
        button.disabled = true;
        if (menu) { menu.open = false; menu.querySelector('summary')?.focus(); }
        clearSuggestions();
        showDiagnostic(null);
        if (diagnosticStatus) diagnosticStatus.textContent = 'KI-Anfrage läuft. Die erfasste Anfrage erscheint mit der Antwort.';
        clearRecognitionNotes();
        showStatus('KI-Vorschlag wird erstellt …');
        try {
            const currentFields = Object.fromEntries(Object.entries(fieldMap).map(([key, [name]]) => [key, field(name)?.value || '']));
            if (window.eablageNumbers) currentFields.numbers = window.eablageNumbers.values();
            currentFields.category2 = field('Kategorie2')?.value || '';
            const result = await request({action: 'suggest', aktion_token: field('aktion_token').value,
                filename: field('original_datei').value, sha256: field('source_sha256').value,
                job_id: field('inbox_job_id').value, text_sha256: button.dataset.textSha256, current_fields: JSON.stringify(currentFields)}, 320000);
            render(result, false);
        } catch (error) {
            showDiagnostic(error.diagnostic);
            showRecognitionNotes({error: error.message});
            showFailure('KI-Vorschlag konnte nicht erstellt werden.');
        }
        finally { busy = false; button.disabled = false; }
    });
    async function pollBackground() {
        if (backgroundCancelled || busy) return;
        try {
            const result = await request({action: 'background', aktion_token: field('aktion_token').value,
                filename: field('original_datei').value, sha256: field('source_sha256').value,
                job_id: field('inbox_job_id').value, text_sha256: button.dataset.textSha256}, 12000);
            if (backgroundCancelled || busy) return;
            if (result.status === 'aus') {
                showStatus('');
                return;
            }
            if (result.status === 'bereit') {
                clearSuggestions();
                render(result.suggestion, true);
                return;
            }
            const message = result.progress?.message || (result.status === 'fehler'
                ? 'KI fehlgeschlagen. Manuelle Klassifizierung bleibt möglich.' : 'KI-Auftrag wartet auf den Worker.');
            const workerUnavailable = result.worker?.enabled && (!result.worker.available || !result.worker.fresh);
            const workerMessage = workerUnavailable ? result.worker.message || 'Keine aktuelle Worker-Rückmeldung.' : '';
            showRecognitionNotes({
                history: result.status === 'fehler' ? result.attempt_history || result.progress?.attempt_history || unknownHistory : null,
                message: [message, workerMessage].filter(Boolean).join(' '),
                error: result.progress?.error || result.error || '', lastAttempt: result.progress?.last_attempt
            });
            if (result.status === 'fehler') showFailure('KI fehlgeschlagen.');
            else if (workerUnavailable) showFailure('KI-Status derzeit nicht verfügbar.');
            else {
                const states = {wartend:'KI wartet.', verarbeitung:'KI läuft.', pausiert:'KI-Verarbeitung pausiert.'};
                showStatus(states[result.progress?.state || result.status] || 'KI wartet.');
            }
            if (result.retryable !== false) setTimeout(pollBackground, 5000);
        } catch (error) {
            if (backgroundCancelled || busy) return;
            showRecognitionNotes({error: error.message});
            showFailure('KI-Status konnte nicht abgefragt werden.');
        }
    }
    form.addEventListener('submit', () => { backgroundCancelled = true; });
    window.addEventListener('pagehide', () => { backgroundCancelled = true; });
    if (button.dataset.background === 'true') pollBackground();
})();

