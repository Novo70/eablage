(() => {
    const buttons = document.querySelectorAll('[data-help-tooltip]');
    if (!buttons.length) return;
    // Keep help outside the scrolling containers so they cannot clip it.
    const tooltip = document.createElement('div');
    tooltip.id = 'ea-help-tooltip';
    tooltip.className = 'ea-help-tooltip';
    tooltip.setAttribute('role', 'tooltip');
    tooltip.hidden = true;
    document.body.append(tooltip);
    let active = null, pinned = false;
    const hide = () => {
        if (active) {
            active.removeAttribute('aria-describedby');
            active.setAttribute('aria-expanded', 'false');
        }
        active = null; pinned = false; tooltip.hidden = true;
    };
    const show = (button, pin = false) => {
        hide(); active = button; pinned = pin;
        tooltip.textContent = button.dataset.helpTooltip;
        tooltip.hidden = false;
        button.setAttribute('aria-describedby', tooltip.id);
        button.setAttribute('aria-expanded', 'true');
        const rect = button.getBoundingClientRect(), box = tooltip.getBoundingClientRect();
        tooltip.style.left = Math.max(16, Math.min(rect.left, innerWidth - box.width - 16)) + 'px';
        tooltip.style.top = Math.max(16, rect.bottom + box.height + 8 <= innerHeight
            ? rect.bottom + 8 : rect.top - box.height - 8) + 'px';
    };
    for (const button of buttons) {
        button.removeAttribute('title');
        button.setAttribute('aria-expanded', 'false');
        button.addEventListener('pointerenter', event => { if (event.pointerType === 'mouse' && !pinned) show(button); });
        button.addEventListener('pointerleave', () => { if (active === button && !pinned) hide(); });
        button.addEventListener('focus', () => show(button));
        button.addEventListener('blur', () => { if (active === button && !pinned) hide(); });
        button.addEventListener('click', () => { if (active === button && pinned) hide(); else show(button, true); });
    }
    document.addEventListener('keydown', event => { if (event.key === 'Escape') hide(); });
    document.addEventListener('pointerdown', event => { if (active && !active.contains(event.target)) hide(); });
    window.addEventListener('scroll', hide, true);
    window.addEventListener('resize', hide);
})();
