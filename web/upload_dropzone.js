'use strict';

(function () {
    const dropzone = document.getElementById('pdf_dropzone');
    const input = document.getElementById('pdf_datei');
    const status = document.getElementById('pdf_drop_status');
    if (!dropzone || !input || !status) return;

    let dragDepth = 0;
    function feedback(message, error) {
        status.textContent = message;
        status.classList.toggle('is-error', error);
    }
    input.addEventListener('change', function () {
        const file = input.files && input.files[0];
        feedback(file ? 'Ausgewählt: ' + file.name : '', false);
    });
    dropzone.addEventListener('dragenter', function (event) {
        event.preventDefault();
        dragDepth += 1;
        dropzone.classList.add('is-dragover');
    });
    dropzone.addEventListener('dragover', function (event) {
        event.preventDefault();
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
    });
    dropzone.addEventListener('dragleave', function (event) {
        event.preventDefault();
        dragDepth = Math.max(0, dragDepth - 1);
        if (!dragDepth) dropzone.classList.remove('is-dragover');
    });
    dropzone.addEventListener('drop', function (event) {
        event.preventDefault();
        dragDepth = 0;
        dropzone.classList.remove('is-dragover');
        const files = event.dataTransfer && event.dataTransfer.files;
        if (!files || files.length !== 1) {
            input.value = '';
            feedback('Bitte genau eine PDF-Datei ablegen.', true);
            return;
        }
        if (!/\.pdf$/i.test(files[0].name)) {
            input.value = '';
            feedback('Es dürfen nur PDF-Dateien hochgeladen werden.', true);
            return;
        }
        try {
            input.files = files;
            if (!input.files || input.files.length !== 1) throw new Error('File assignment failed');
            input.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (_error) {
            input.value = '';
            feedback('Die Datei konnte nicht übernommen werden. Bitte über „Auswählen“ öffnen.', true);
        }
    });
}());
