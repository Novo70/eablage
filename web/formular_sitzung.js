// Keep the form in the tab while checking its session before submitting.
(() => {
    const pending = new WeakSet();
    const checked = new WeakSet();
    document.addEventListener('submit', async event => {
        const form = event.target;
        const token = form.elements.namedItem('aktion_token');
        if (!form.hasAttribute('data-session-check') || !token || checked.has(form)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (pending.has(form)) return;
        pending.add(form);
        const submitter = event.submitter;
        try {
            const response = await fetch('formular_token.php', {
                headers: {'X-eAblage-Formular': '1'}, mode: 'same-origin', cache: 'no-store'
            });
            if (!response.ok) throw new Error();
            const result = await response.json();
            if (typeof result.token !== 'string' || !/^[a-f0-9]{64}$/.test(result.token)) throw new Error();
            if (token.value !== result.token) {
                token.value = result.token;
                window.alert('Die Sitzung wurde erneuert. Ihre Eingaben sind erhalten. Bitte prüfen Sie das Formular und bestätigen Sie die Aktion erneut.');
                return;
            }
            checked.add(form);
            form.requestSubmit(submitter ?? undefined);
        } catch {
            window.alert('Die Sitzung konnte nicht geprüft werden. Ihre Eingaben sind erhalten. Bitte erneut versuchen.');
        } finally {
            checked.delete(form);
            pending.delete(form);
        }
    }, true);
})();
