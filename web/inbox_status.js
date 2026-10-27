(() => {
    const labels = {wartend:'Wartet auf Erkennung', verarbeitung:'In Verarbeitung', bereit:'Zur Prüfung bereit', fehler:'Erkennung fehlgeschlagen', archiviert:'Bereits archiviert', verworfen:'Wartet auf Erkennung'};
    const kiLabels = {wartend:'KI wartet.', verarbeitung:'KI läuft.', bereit:'KI-Vorschlag verfügbar.', fehler:'KI fehlgeschlagen.', pausiert:'KI-Verarbeitung pausiert.'};
    async function refresh() {
        if (!document.hidden) {
            try {
                const elements = Array.from(document.querySelectorAll('[data-inbox-status]'));
                const filenames = [...new Set(elements.map(el => el.dataset.inboxStatus))];
                const jobs = new Map();
                let worker = null;
                for (let offset = 0; offset < Math.max(1, filenames.length); offset += 200) {
                    const body = new URLSearchParams();
                    filenames.slice(offset, offset + 200).forEach(name => body.append('filenames[]', name));
                    const response = await fetch('inbox_status.php', {method:'POST', body, cache:'no-store'});
                    if (!response.ok) throw new Error('Status unavailable');
                    const data = await response.json();
                    worker = data.ki_worker;
                    data.jobs.forEach(job => jobs.set(job.dateiname, job));
                }
                elements.forEach(el => {
                    const job = jobs.get(el.dataset.inboxStatus);
                    el.textContent = labels[job?.status] || labels.wartend;
                    el.title = job?.fehler || '';
                    const block = el.parentElement?.querySelector('[data-inbox-ai-block]');
                    if (block) {
                        block.hidden = !job?.ki;
                        block.querySelector('[data-inbox-ai-status]').textContent = kiLabels[job?.ki?.state] || '';
                    }
                });
                const notice = document.getElementById?.('ki-worker-status');
                if (notice) {
                    notice.hidden = !worker?.enabled || (worker.fresh && !['pausiert','deaktiviert'].includes(worker.state));
                    notice.textContent = notice.hidden ? '' : worker.message || '';
                }
            } catch (_) {
                const notice = document.getElementById?.('ki-worker-status');
                if (notice) { notice.hidden = false; notice.textContent = 'Status derzeit nicht abfragbar. Manuelle Klassifizierung bleibt möglich.'; }
            }
        }
        setTimeout(refresh, 5000);
    }
    setTimeout(refresh, 5000);
})();
