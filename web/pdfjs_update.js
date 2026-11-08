(() => {
    const button = document.getElementById('pdfjs-update-check');
    const checkStatus = document.getElementById('update-check-status');
    const eablageStatus = document.getElementById('eablage-update-status');
    const pdfjsStatus = document.getElementById('pdfjs-update-status');
    if (!button || !checkStatus || !eablageStatus || !pdfjsStatus) return;

    function line(target, message, label, url) {
        const p = document.createElement('p');
        p.textContent = message;
        if (url) {
            const link = document.createElement('a');
            link.textContent = label;
            link.href = url;
            p.append(' ', link);
        }
        target.append(p);
    }

    function show(result) {
        eablageStatus.replaceChildren();
        pdfjsStatus.replaceChildren();
        if (result.eablage_release === null) {
            line(eablageStatus, 'Öffentliches eAblage-Release derzeit nicht abrufbar.');
        } else {
            const tag = encodeURIComponent(result.eablage_release);
            line(eablageStatus, 'Neuestes öffentliches Release ' + result.eablage_release + '.',
                'Release ansehen', 'https://github.com/Novo70/eablage/releases/tag/' + tag);
            if (result.eablage_update_available) {
                line(eablageStatus, 'eAblage-Aktualisierung verfügbar.', 'Update-Anleitung öffnen',
                    'https://github.com/Novo70/eablage/blob/' + tag + '/docs/UPDATE.md');
            }
        }

        if (result.upstream === null) {
            line(pdfjsStatus, 'Mozilla-Release derzeit nicht abrufbar.');
        } else {
            const note = result.upstream_newer ? ' (neuer als installiert)' : '';
            line(pdfjsStatus, 'Neuestes Mozilla-Release ' + result.upstream + note + '.', 'Release ansehen',
                'https://github.com/mozilla/pdf.js/releases/tag/v' + encodeURIComponent(result.upstream));
        }
        if (result.eablage_pdfjs) {
            line(pdfjsStatus, 'Im öffentlichen eAblage-Release enthalten: PDF.js ' + result.eablage_pdfjs + '.');
        }

        if (result.installed === null) {
            line(pdfjsStatus, 'Für die Sicherheitsprüfung wird die installierte PDF.js-Version benötigt.');
        } else if (result.advisories === null) {
            line(pdfjsStatus, 'Sicherheitsprüfung derzeit nicht verfügbar.');
        } else if (result.advisories.length) {
            line(pdfjsStatus, 'Sicherheitsupdate empfohlen. OSV meldet Sicherheitslücken für die installierte Version.');
            for (const id of result.advisories) {
                line(pdfjsStatus, 'Sicherheitsmeldung', id, 'https://osv.dev/vulnerability/' + encodeURIComponent(id));
            }
        } else {
            line(pdfjsStatus, 'OSV meldet für die installierte Version keine bekannten Sicherheitslücken.');
        }

        checkStatus.textContent = result.last_success
            ? 'Letzte vollständige Prüfung: ' + new Date(result.last_success * 1000).toLocaleString('de-DE')
            : '';
    }

    const cached = JSON.parse(document.getElementById('pdfjs-update-cache').textContent);
    if (cached) show(cached);

    button.addEventListener('click', async () => {
        button.disabled = true;
        checkStatus.textContent = 'Aktualisierungen werden geprüft …';
        try {
            const response = await fetch('pdfjs_update_status.php', {
                method: 'POST',
                body: new URLSearchParams({aktion_token: document.querySelector('#settings-form [name="aktion_token"]').value}),
            });
            const result = await response.json();
            if (!response.ok) {
                checkStatus.textContent = result.error || 'Die Prüfung ist derzeit nicht verfügbar.';
                return;
            }
            show(result);
        } catch (_) {
            checkStatus.textContent = 'Die Prüfung ist derzeit nicht verfügbar.';
        } finally {
            button.disabled = false;
        }
    });
})();
