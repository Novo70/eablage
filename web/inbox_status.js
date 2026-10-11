(() => {
    const labels = {wartend:'Wartet auf Erkennung', verarbeitung:'In Verarbeitung', bereit:'Zur Prüfung bereit', fehler:'Erkennung fehlgeschlagen', archiviert:'Bereits archiviert', verworfen:'Wartet auf Erkennung'};
    async function refresh() {
        if (!document.hidden) {
            try {
                const elements = Array.from(document.querySelectorAll('[data-inbox-status]'));
                const filenames = [...new Set(elements.map(el => el.dataset.inboxStatus))];
                const jobs = new Map();
                for (let offset = 0; offset < filenames.length; offset += 200) {
                    const body = new URLSearchParams();
                    filenames.slice(offset, offset + 200).forEach(name => body.append('filenames[]', name));
                    const response = await fetch('inbox_status.php', {method:'POST', body, cache:'no-store'});
                    if (!response.ok) throw new Error('Status unavailable');
                    const data = await response.json();
                    data.jobs.forEach(job => jobs.set(job.dateiname, job));
                }
                elements.forEach(el => {
                    const job = jobs.get(el.dataset.inboxStatus);
                    el.textContent = labels[job?.status] || labels.wartend;
                    el.title = job?.fehler || '';
                });
            } catch (_) { /* Manual classification remains available. */ }
        }
        setTimeout(refresh, 5000);
    }
    setTimeout(refresh, 5000);
})();
