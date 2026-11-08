/* Load the matching integration and PDF.js modules before starting the viewer. */
const root = new URL('.', import.meta.url);
const params = new URLSearchParams(location.search);
const version = params.get('v') || String(Date.now());
const assetUrl = path => new URL(path + '?v=' + encodeURIComponent(version), root).href;
const buildUrl = name => assetUrl('vendor/pdfjs/build/' + name);

let original = null;
const file = params.get('file');
if (file) {
    try {
        const url = new URL(file, location.href);
        const pdfPaths = ['archiv_pdf.php', 'pdf.php'].map(name => new URL(name, root).pathname);
        if (url.origin === root.origin && !url.username && !url.password && pdfPaths.includes(url.pathname)) {
            url.hash = '';
            original = url;
        }
    } catch (_) { /* Invalid source URLs are handled below. */ }
}
// Discard viewer/debug options and never fall back to the upstream sample PDF.
history.replaceState(null, '', location.pathname + '?file=' +
    encodeURIComponent(original?.href || '') + '&v=' + encodeURIComponent(version) + location.hash);

const notice = document.createElement('div');
notice.id = 'ea-pdf-error';
notice.hidden = true;
notice.setAttribute('role', 'alert');
const message = document.createTextNode('');
notice.append(message);
if (original) {
    const link = document.createElement('a');
    link.href = original.href;
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = 'PDF öffnen';
    notice.append(link);
}
document.body.append(notice);
let firstPageTimer;
function showNotice(text) {
    message.textContent = text + (original ? ' ' : '');
    notice.hidden = false;
}
function showError() {
    clearTimeout(firstPageTimer);
    showNotice(original ? 'Die PDF-Vorschau konnte nicht geladen werden.' : 'Keine gültige eAblage-PDF ausgewählt.');
}
function rendered() {
    clearTimeout(firstPageTimer);
    notice.hidden = true;
}
function checkFirstPage() {
    // The iframe can already be loading while the Text tab is active.
    if (document.hidden || window.frameElement?.getClientRects().length === 0) {
        firstPageTimer = setTimeout(checkFirstPage, 15000);
        return;
    }
    showNotice('Die PDF-Vorschau konnte noch nicht angezeigt werden.');
}
if (original) firstPageTimer = setTimeout(checkFirstPage, 15000);
else showError();
window.addEventListener('error', showError);
window.addEventListener('unhandledrejection', showError);

const styles = document.createElement('link');
styles.rel = 'stylesheet';
styles.href = assetUrl('pdfjs.css');
const styled = new Promise(resolve => {
    styles.addEventListener('load', resolve, {once: true});
    styles.addEventListener('error', resolve, {once: true});
});
document.head.append(styles);
try {
    const { configurePreview } = await import(assetUrl('pdfjs.js'));
    configurePreview({ original, assetUrl, buildUrl, showError, rendered });
    await Promise.all([styled, import(buildUrl('pdf.mjs'))]);
    await import(assetUrl('vendor/pdfjs/web/viewer.mjs'));
} catch (error) {
    console.error('eAblage PDF-Vorschau', error);
    showError();
}
