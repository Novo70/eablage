/* Dismiss only workspace notices; form values and validation remain unchanged. */
document.querySelectorAll('.ea-workspace-notices > .alert').forEach(message => {
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'ea-alert-close';
    close.setAttribute('aria-label', 'Meldung schließen');
    close.title = 'Meldung schließen';
    close.textContent = '×';
    close.addEventListener('click', () => message.remove());
    message.classList.add('ea-alert-dismissible');
    message.append(close);
});
