/* Browserlokale Breitenwahl für alle zweigeteilten Dokumentarbeitsplätze. */
(() => {
    const root = document.documentElement;
    const buttons = Array.from(document.querySelectorAll('[data-preview-split-toggle]'));
    if (!buttons.length) return;
    // Die Vorschaubreite ist nur neben der Seitenleiste wählbar; die Grenze kommt aus app.css.
    const sidebarBreakpoint = (typeof window.getComputedStyle === 'function'
        && window.getComputedStyle(root).getPropertyValue('--ea-sidebar-breakpoint').trim()) || '1280px';
    const desktop = window.matchMedia('(min-width: ' + sidebarBreakpoint + ')');
    const wideWindow = window.matchMedia('(min-width: 1600px)');
    let split = 'balanced';
    try {
        if (window.localStorage.getItem('eablage.previewSplit') === 'wide') split = 'wide';
    } catch (_) { /* Die Auswahl funktioniert auch ohne dauerhafte Speicherung. */ }

    function render() {
        root.dataset.eaPreviewSplit = split;
        const available = desktop.matches;
        const wide = split === 'wide' && wideWindow.matches;
        buttons.forEach(button => {
            button.hidden = !available;
            button.disabled = !wideWindow.matches;
            button.querySelector('use').setAttribute('href', 'icons.svg#' +
                (wide ? 'collapse-horizontal' : 'expand-horizontal'));
            button.setAttribute('aria-pressed', String(wide));
            const action = !wideWindow.matches
                ? 'Vorschau verbreitern: ab 1600 Pixeln Fensterbreite verfügbar'
                : wide ? 'Vorschau schmaler anzeigen' : 'Vorschau verbreitern';
            button.setAttribute('aria-label', action);
            button.title = action;
        });
    }

    buttons.forEach(button => button.addEventListener('click', () => {
        if (!desktop.matches || !wideWindow.matches) return;
        split = split === 'wide' ? 'balanced' : 'wide';
        try { window.localStorage.setItem('eablage.previewSplit', split); } catch (_) {}
        render();
    }));
    desktop.addEventListener('change', render);
    wideWindow.addEventListener('change', render);
    render();
})();



