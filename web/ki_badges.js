/* Shared evidence tooltip: hover, keyboard focus or tap; never submits a form. */
(() => {
    if (window.eablageAiBadges) return;
    const tooltip = document.createElement('div');
    tooltip.id = 'ea-ai-evidence-tooltip'; tooltip.className = 'ea-ai-evidence-tooltip';
    tooltip.setAttribute('role', 'tooltip'); tooltip.hidden = true;
    document.body.append(tooltip);
    let active = null, pinned = false;
    function hide() {
        if (active) {
            active.removeAttribute('aria-describedby');
            active.setAttribute('aria-expanded', 'false');
        }
        active = null; pinned = false; tooltip.hidden = true;
    }
    function show(badge, pin = false) {
        if (!badge?.dataset.aiEvidence || badge.closest('[hidden]')) return;
        if (active !== badge) hide();
        active = badge; pinned = pin;
        tooltip.textContent = badge.dataset.aiTooltip;
        tooltip.hidden = false;
        badge.setAttribute('aria-describedby', tooltip.id);
        badge.setAttribute('aria-expanded', 'true');
        const rect = badge.getBoundingClientRect(), box = tooltip.getBoundingClientRect();
        tooltip.style.left = Math.max(12, Math.min(rect.left, innerWidth - box.width - 12)) + 'px';
        tooltip.style.top = Math.max(12, rect.bottom + box.height + 8 <= innerHeight
            ? rect.bottom + 8 : rect.top - box.height - 8) + 'px';
    }
    const badgeFor = target => target instanceof Element ? target.closest('[data-ai-evidence]') : null;
    document.addEventListener('pointerover', event => {
        const badge = badgeFor(event.target);
        if (badge && event.pointerType === 'mouse' && !badge.contains(event.relatedTarget) && !pinned) show(badge);
    });
    document.addEventListener('pointerout', event => {
        if (active && active.contains(event.target) && !active.contains(event.relatedTarget) && !pinned) hide();
    });
    document.addEventListener('focusin', event => {
        const badge = badgeFor(event.target);
        if (badge) show(badge);
    });
    document.addEventListener('focusout', event => {
        if (active === event.target && !pinned) hide();
    });
    document.addEventListener('pointerdown', event => {
        if (active && !active.contains(event.target) && !tooltip.contains(event.target)) hide();
    });
    function activate(event, badge) {
        event.preventDefault(); event.stopPropagation();
        if (active === badge && pinned) hide(); else show(badge, true);
    }
    document.addEventListener('click', event => {
        const badge = badgeFor(event.target);
        if (badge) activate(event, badge);
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') { hide(); return; }
        const badge = badgeFor(event.target);
        if (badge && ['Enter', ' '].includes(event.key)) activate(event, badge);
    });
    window.addEventListener('resize', hide);
    window.addEventListener('scroll', hide, true);
    window.eablageAiBadges = {
        decorate(badge, model, evidence = '') {
            if (active === badge && badge.dataset.aiEvidence !== evidence) hide();
            const marker = 'KI (Ollama: ' + model + ')';
            badge.title = evidence ? '' : marker;
            if (evidence) {
                badge.dataset.aiEvidence = evidence;
                badge.dataset.aiTooltip = marker + '\nTextbeleg: ' + evidence;
                badge.tabIndex = 0; badge.setAttribute('role', 'button');
                badge.setAttribute('aria-controls', tooltip.id);
                if (active !== badge) badge.setAttribute('aria-expanded', 'false');
            } else {
                delete badge.dataset.aiEvidence; delete badge.dataset.aiTooltip;
                for (const attribute of ['tabindex', 'role', 'aria-controls', 'aria-expanded', 'aria-describedby']) badge.removeAttribute(attribute);
            }
        },
        dismiss(element) { if (active && (active === element || element?.contains(active))) hide(); }
    };
})();
