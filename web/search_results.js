/* Reveal invalid criteria before the browser focuses the affected control. */
(() => {
    const form = document.getElementById('search-refine-form');
    const filter = document.getElementById('search-refine');
    if (!form || !filter) return;
    form.addEventListener('invalid', () => { filter.open = true; }, true);
})();
