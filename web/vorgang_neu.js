(() => {
    const dialog = document.getElementById('vorgang-create-dialog');
    const open = document.getElementById('vorgang-create-open');
    if (!dialog || !open) return;

    open.addEventListener('click', () => dialog.showModal());
    document.getElementById('vorgang-create-cancel').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => open.focus());
    if (dialog.querySelector('[role="alert"]')) dialog.showModal();
})();
