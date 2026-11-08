/* Shared viewer loading and PDF/text controls. Form-draft IDs remain supported. */
(function (root) {
    'use strict';
    function previewUrl(pdfUrl, version = '') {
        return 'vendor/pdfjs/web/viewer.html?file=' +
            encodeURIComponent('../../../' + pdfUrl.split('#')[0]) +
            (version ? '&v=' + encodeURIComponent(version) : '') + '#zoom=page-width';
    }
    if (typeof module !== 'undefined' && module.exports) module.exports = {previewUrl};
    if (!root.document) return;
    const controllers = new WeakMap();
    function controller(panel) {
        if (controllers.has(panel)) return controllers.get(panel);
        const pdf = panel.querySelector('[data-preview-pdf]');
        const text = panel.querySelector('[data-preview-text]');
        const content = panel.querySelector('[data-preview-text-content]');
        const empty = panel.querySelector('[data-preview-empty]');
        const open = panel.querySelector('[data-preview-open]');
        const buttons = Array.from(panel.querySelectorAll('[data-preview-view]'));
        let spreadMode = 0;
        let current = null, view = 'pdf', request = null, version = 0, loaded = false;
        async function loadText() {
            if (!current?.textUrl || loaded || request) return;
            const token = version;
            const pending = new AbortController();
            request = pending;
            content.textContent = 'Dokumenttext wird geladen …';
            text.setAttribute('aria-busy', 'true');
            try {
                const response = await root.fetch(current.textUrl, {signal: pending.signal, credentials: 'same-origin', cache: 'no-store'});
                if (!response.ok) throw new Error('Text unavailable');
                const data = await response.json();
                if (typeof data.text !== 'string') throw new Error('Invalid text response');
                if (version !== token) return;
                content.textContent = data.text || 'Kein Dokumenttext vorhanden.';
                loaded = true;
            } catch (_) {
                if (version === token && !pending.signal.aborted) {
                    content.textContent = 'Der Dokumenttext konnte nicht geladen werden. Zum erneuten Laden bitte nochmals „Text“ wählen.';
                }
            } finally {
                if (version === token) {
                    request = null;
                    text.removeAttribute('aria-busy');
                }
            }
        }
        function setView(value) {
            view = value === 'text' ? 'text' : 'pdf';
            const selected = Boolean(current?.pdfUrl);
            pdf.hidden = !selected || view === 'text';
            text.hidden = !selected || view !== 'text';
            buttons.forEach(button => {
                const active = button.dataset.previewView === view;
                button.disabled = !selected;
                button.classList.toggle('active', active);
                button.setAttribute('aria-pressed', String(active));
            });
            if (selected && view === 'text') loadText();
        }
        function select(document) {
            if (current && document && current.pdfUrl === document.pdfUrl && current.textUrl === document.textUrl) return;
            request?.abort();
            request = null;
            version++;
            current = document;
            loaded = !document?.textUrl;
            text.removeAttribute('aria-busy');
            content.textContent = document?.text ?? '';
            pdf.replaceChildren();
            empty.hidden = Boolean(document?.pdfUrl);
            open.hidden = !document?.pdfUrl;
            if (document?.pdfUrl) {
                open.href = document.pdfUrl;
                const frame = root.document.createElement('iframe');
                frame.title = 'PDF-Vorschau';
                frame.dataset.previewSpreadMode = String(spreadMode);
                frame.addEventListener('eablage:pdf-spread-changed', event => {
                    if (frame !== pdf.firstElementChild || ![0, 1, 2].includes(event.detail?.spreadMode)) return;
                    spreadMode = event.detail.spreadMode;
                });
                frame.src = previewUrl(document.pdfUrl, panel.dataset.previewVersion);
                pdf.append(frame);
            } else {
                open.removeAttribute('href');
                view = 'pdf';
            }
            setView(view);
        }
        const api = {select, setView};
        controllers.set(panel, api);
        buttons.forEach(button => button.addEventListener('click', () => setView(button.dataset.previewView)));
        const initialText = content.textContent;
        select(panel.dataset.previewUrl ? {pdfUrl: panel.dataset.previewUrl, text: initialText} : null);
        return api;
    }
    root.eablageSelectDocumentPreview = (panel, document) => controller(panel).select(document);
    root.eablageSetDocumentView = view => {
        const panel = root.document.querySelector('[data-document-preview]');
        if (panel) controller(panel).setView(view);
    };
    root.document.querySelectorAll('[data-document-preview]').forEach(controller);
})(typeof window !== 'undefined' ? window : globalThis);
