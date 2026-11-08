/* eAblage integration for the locally bundled, otherwise unchanged PDF.js viewer. */
export function configurePreview({ original, assetUrl, buildUrl, showError, rendered }) {
    // PDF.js dispatches this event on the embedding document before initialization.
    let target = document;
    try { target = window.parent.document; } catch (_) { /* Direct cross-origin embedding. */ }
    function configure(event) {
        if (event.detail?.source !== window) return;
        target.removeEventListener('webviewerloaded', configure);
        const hostFrame = window.frameElement;
        const requestedSpread = () => {
            const mode = Number(hostFrame?.dataset.previewSpreadMode ?? 0);
            return [0, 1, 2].includes(mode) ? mode : 0;
        };
        const options = window.PDFViewerApplicationOptions;
        options.setAll({
            defaultUrl: '',
            workerSrc: buildUrl('pdf.worker.mjs'),
            rendererSrc: buildUrl('pdf.renderer.mjs'),
            sandboxBundleSrc: buildUrl('pdf.sandbox.mjs'),
            defaultZoomValue: 'page-width',
            spreadModeOnLoad: requestedSpread(),
            disablePreferences: true,
            disableHistory: true,
            localeProperties: {lang: 'de'},
            annotationEditorMode: -1,
            annotationMode: 1,
            enableScripting: false,
            isEvalSupported: false,
            enableComment: false,
            enableSignatureEditor: false,
            enableAltTextModelDownload: false,
            enableGuessAltText: false,
            enableNewAltTextWhenAddingImage: false,
            externalLinkTarget: 2,
        });
        const app = window.PDFViewerApplication;
        app.initializedPromise.then(() => {
            app.eventBus.on('documenterror', showError);
            app.eventBus.on('pagerendered', ({ error, cssTransform, isDetailView }) => {
                if (error) showError();
                else if (!cssTransform && !isDetailView) rendered();
            });
            // Keep the custom control in our integration layer, not in upstream viewer files.
            const spreadButton = document.createElement('button');
            spreadButton.id = 'eaSpreadToggle';
            spreadButton.type = 'button';
            spreadButton.className = 'toolbarButton';
            spreadButton.setAttribute('aria-label', 'Zweiseitige Vorschau');
            spreadButton.disabled = !original;
            const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            icon.setAttribute('viewBox', '0 0 24 24');
            icon.setAttribute('fill', 'none');
            icon.setAttribute('stroke', 'currentColor');
            icon.setAttribute('stroke-width', '1.7');
            icon.setAttribute('stroke-linecap', 'round');
            icon.setAttribute('stroke-linejoin', 'round');
            icon.setAttribute('aria-hidden', 'true');
            const shape = document.createElementNS('http://www.w3.org/2000/svg', 'use');
            icon.append(shape);
            spreadButton.append(icon);
            document.getElementById('toolbarViewerRight').prepend(spreadButton);
            let spreadReady = false;
            let desiredSpread = requestedSpread();
            function renderSpread(mode) {
                const twoPages = mode !== 0;
                spreadButton.setAttribute('aria-pressed', String(twoPages));
                spreadButton.classList.toggle('toggled', twoPages);
                spreadButton.title = twoPages ? 'Einseitig anzeigen' : 'Zweiseitig anzeigen';
                shape.setAttribute('href', assetUrl('icons.svg') + '#' + (twoPages ? 'pages-single' : 'pages-double'));
            }
            function rememberSpread(mode) {
                if (!hostFrame) return;
                hostFrame.dataset.previewSpreadMode = String(mode);
                hostFrame.dispatchEvent(new CustomEvent('eablage:pdf-spread-changed', {detail: {spreadMode: mode}}));
            }
            function announceSpread(mode) {
                if (!spreadReady) return;
                desiredSpread = mode;
                renderSpread(mode);
                rememberSpread(mode);
            }
            function applySpread() {
                if (!spreadReady) return;
                app.pdfViewer.spreadMode = desiredSpread;
                announceSpread(app.pdfViewer.spreadMode);
            }
            spreadButton.addEventListener('click', () => {
                desiredSpread = desiredSpread === 0 ? 1 : 0;
                renderSpread(desiredSpread);
                rememberSpread(desiredSpread);
                applySpread();
            });
            renderSpread(desiredSpread);
            app.eventBus.on('spreadmodechanged', event => announceSpread(event.mode));
            app.eventBus.on('documentinit', () => {
                spreadReady = true;
                applySpread();
            });

            // Resizing a split pane does not necessarily resize the browser window.
            // Reapply only automatic zoom modes, preserving manual zoom and current page.
            const container = document.getElementById('viewerContainer');
            new ResizeObserver(() => {
                const viewer = app.pdfViewer;
                if (viewer?.pdfDocument && ['auto', 'page-width', 'page-fit', 'page-height'].includes(viewer.currentScaleValue)) {
                    viewer.currentScaleValue = viewer.currentScaleValue;
                }
            }).observe(container);
        }).catch(showError);
    }
    target.addEventListener('webviewerloaded', configure);
    window.addEventListener('pagehide', () => target.removeEventListener('webviewerloaded', configure), {once: true});
}
