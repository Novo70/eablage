/* One explanation field per row: visible on wide screens, collapsible on mobile. */
(() => {
    const table = document.querySelector('.ea-master-table');
    if (!table) return;
    const wide = window.matchMedia('(min-width: 900px)');
    const explanations = [...table.querySelectorAll('.ea-master-explanation')];
    const mobileState = new WeakMap(explanations.map(details => [details, details.open]));
    for (const details of explanations) {
        // Remember the user action synchronously, before the native toggle.
        // A queued toggle event could otherwise arrive after a viewport change.
        details.querySelector('summary').addEventListener('click', () => {
            if (!wide.matches) mobileState.set(details, !details.open);
        });
    }
    const update = () => {
        table.toggleAttribute('data-master-wide', wide.matches);
        for (const details of explanations) details.open = wide.matches || mobileState.get(details);
    };
    wide.addEventListener('change', update);
    update();
})();
