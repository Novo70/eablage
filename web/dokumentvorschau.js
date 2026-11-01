/* Keep the established IDs and function for form drafts and refreshed OCR text. */
window.eablageSetDocumentView = function(view) {
    const pdf = document.getElementById('pdfDocumentView');
    const text = document.getElementById('textDocumentView');
    const pdfButton = document.getElementById('viewPdfButton');
    const textButton = document.getElementById('viewTextButton');
    const showText = view === 'text';
    pdf.hidden = showText;
    text.hidden = !showText;
    pdfButton.classList.toggle('active', !showText);
    textButton.classList.toggle('active', showText);
    pdfButton.setAttribute('aria-pressed', String(!showText));
    textButton.setAttribute('aria-pressed', String(showText));
};
