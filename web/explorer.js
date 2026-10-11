(function (root) {
    'use strict';
    const collator = new Intl.Collator('de', {numeric: true, sensitivity: 'base'});
    const pathKey = parts => JSON.stringify(parts);

    function compareDocuments(a, b) {
        const firstDate = String(a.display?.date ?? '');
        const secondDate = String(b.display?.date ?? '');
        // ISO dates sort newest first; documents without a date come last.
        if (firstDate !== secondDate) {
            if (!firstDate) return 1;
            if (!secondDate) return -1;
            return firstDate < secondDate ? 1 : -1;
        }
        for (const field of ['category', 'type', 'partner', 'subject', 'number']) {
            const result = collator.compare(String(a.display?.[field] ?? ''), String(b.display?.[field] ?? ''));
            if (result) return result;
        }
        return a.dok_id - b.dok_id;
    }

    function buildTree(plan) {
        if (!plan || plan.profile !== 1 || !Array.isArray(plan.items)) throw new Error('Ungültiger Exportplan.');
        const tree = {name: 'Ablage', parts: [], children: new Map(), documents: [], count: 0};
        const nodes = new Map([[pathKey([]), tree]]);
        const documents = new Map();
        for (const item of plan.items) {
            if (!Number.isSafeInteger(item.dok_id) || item.dok_id < 1 || documents.has(item.dok_id)
                || typeof item.filename !== 'string' || !item.filename
                || !Array.isArray(item.directories)
                || item.directories.some(part => typeof part !== 'string' || !part)) {
                throw new Error('Ungültiger Dokumenteintrag im Exportplan.');
            }
            let node = tree;
            node.count++;
            for (const part of item.directories) {
                if (!node.children.has(part)) {
                    const child = {name: part, parts: [...node.parts, part], children: new Map(), documents: [], count: 0};
                    node.children.set(part, child);
                    nodes.set(pathKey(child.parts), child);
                }
                node = node.children.get(part);
                node.count++;
            }
            const document = {...item, node};
            node.documents.push(document);
            documents.set(item.dok_id, document);
        }
        for (const node of nodes.values()) {
            node.documents.sort(compareDocuments);
        }
        return {tree, nodes, documents};
    }

    function selectionFromUrl(model, href) {
        const params = new URL(href).searchParams;
        const id = params.get('dokid');
        const document = /^\d+$/.test(id || '') ? model.documents.get(Number(id)) : null;
        let parts;
        try { parts = JSON.parse(params.get('folder') || '[]'); } catch (_) { parts = []; }
        if (!Array.isArray(parts) || parts.some(part => typeof part !== 'string')) parts = [];
        while (parts.length && !model.nodes.has(pathKey(parts))) parts.pop();
        const node = model.nodes.get(pathKey(parts)) || model.tree;
        const query = (params.get('q') || '').trim();
        // Keep the filtered scope, or follow a moved/renamed document after editing.
        if (document) {
            if (query && listEntries(node, query).some(entry => entry.doc === document)) {
                return {node, document, query, missing: false};
            }
            return {node: document.node, document, query: '', missing: false};
        }
        return {node, document: null, query, missing: Boolean(id)};
    }

    function viewUrl(node, document, query = '') {
        const params = new URLSearchParams();
        if (node.parts.length) params.set('folder', pathKey(node.parts));
        if (document) params.set('dokid', String(document.dok_id));
        if (query.trim()) params.set('q', query.trim());
        return 'explorer.php' + (params.size ? '?' + params.toString() : '');
    }

    function documentLinks(node, document, query = '') {
        const id = encodeURIComponent(String(document.dok_id));
        return {pdf: 'archiv_pdf.php?dokid=' + id,
            edit: 'bearbeiten.php?dokid=' + id + '&return=' + encodeURIComponent(viewUrl(node, document, query))};
    }

    function sortedChildren(node) {
        return [...node.children.values()].sort((a, b) => collator.compare(a.name, b.name));
    }

    const displayFields = ['date', 'category', 'type', 'partner', 'subject', 'number'];
    function displayText(doc) {
        return displayFields.map(field => String(doc.display?.[field] ?? '')).join(' ').normalize('NFC').toLocaleLowerCase('de');
    }

    function listEntries(node, query = '') {
        const needle = query.trim().normalize('NFC').toLocaleLowerCase('de');
        if (!needle) return [...sortedChildren(node).map(node => ({node})), ...node.documents.map(doc => ({doc}))];
        const matches = [];
        function visit(folder) {
            for (const doc of folder.documents) {
                if (displayText(doc).includes(needle)) matches.push({doc});
            }
            for (const child of folder.children.values()) visit(child);
        }
        visit(node);
        return matches.sort((a, b) => compareDocuments(a.doc, b.doc));
    }

    function mount(document, window) {
        const get = id => document.getElementById('explorer-' + id);
        // A browser Back must not resurrect the pre-edit plan from its page cache.
        window.addEventListener('pageshow', event => { if (event.persisted) window.location.reload(); });
        if (!get('plan')) return;
        let model;
        try { model = buildTree(JSON.parse(get('plan').textContent)); }
        catch (_) {
            get('notice').hidden = false;
            get('notice').textContent = 'Die Ablagestruktur konnte nicht angezeigt werden. Bitte erneut laden und das Backend prüfen.';
            return;
        }
        let current = model.tree;
        let selected = null;
        let query = '';
        let page = 0;
        const pageSize = 100;
        function element(tag, text, className) {
            const node = document.createElement(tag);
            if (text !== undefined) node.textContent = text;
            if (className) node.className = className;
            return node;
        }
        function button(text, className, action) {
            const node = element('button', text, className);
            node.type = 'button';
            node.addEventListener('click', action);
            return node;
        }
        get('layout').hidden = false;

        function updateUrl(push) {
            const url = viewUrl(current, selected, query);
            if (push) window.history.pushState(null, '', url);
            else window.history.replaceState(null, '', url);
        }
        function renderList(focusId) {
            const entries = listEntries(current, query);
            const pageCount = Math.max(1, Math.ceil(entries.length / pageSize));
            page = Math.max(0, Math.min(page, pageCount - 1));
            get('items').replaceChildren();
            get('count').textContent = query.trim()
                ? entries.length + ' Treffer in ' + current.count + ' Dokumenten einschließlich Unterordnern'
                : current.children.size + ' Unterordner · ' + current.documents.length
                  + (current.documents.length === 1 ? ' Dokument in diesem Ordner' : ' Dokumente in diesem Ordner');
            get('empty').hidden = entries.length !== 0;
            get('empty').textContent = query.trim() ? 'Keine passenden Dokumente gefunden.'
                : model.documents.size === 0 ? 'Es sind noch keine Dokumente archiviert.' : 'Dieser Ordner enthält keine Dokumente.';
            for (const entry of entries.slice(page * pageSize, (page + 1) * pageSize)) {
                const li = element('li');
                let item;
                if (entry.node) {
                    const countLabel = entry.node.count === 1 ? '1 Dokument' : entry.node.count + ' Dokumente';
                    item = button('📁 ' + entry.node.name + ' (' + countLabel + ')', 'ea-explorer-item', () => navigate(entry.node, null));
                } else {
                    const doc = entry.doc;
                    li.className = 'ea-explorer-document-row';
                    item = button('', 'ea-explorer-item ea-explorer-document', () => navigate(current, doc, true));
                    item.dataset.dokid = String(doc.dok_id);
                    const info = doc.display || {};
                    const meta = element('span', undefined, 'ea-explorer-document-meta');
                    const date = element('span', info.date || 'Ohne Datum', 'ea-explorer-document-date');
                    meta.append(date);
                    for (const [field, badge] of [['category', 'ea-badge-category'], ['type', 'ea-badge-type']]) {
                        if (info[field]) meta.append(element('span', info[field], 'ea-badge ' + badge));
                    }
                    item.append(meta);
const titleLine = element('span', undefined, 'ea-explorer-document-line');
if (info.partner) {
    titleLine.append(element('span', info.partner, 'ea-explorer-document-partner'));
}
titleLine.append(element('span', info.subject || 'Ohne Betreff', 'ea-explorer-document-subject'));
item.append(titleLine);
                    if (info.number) item.append(element('span', 'Nr. ' + info.number, 'ea-explorer-document-number'));
                    if (query.trim() && doc.node !== current) {
                        item.append(element('span', 'Ordner: ' + doc.node.parts.slice(current.parts.length).join(' / '), 'ea-explorer-document-path'));
                    }
                    if (selected?.dok_id === doc.dok_id) item.setAttribute('aria-current', 'true');
                }
                li.append(item);
                get('items').append(li);
                if (entry.doc?.dok_id === focusId) item.focus({preventScroll: true});
            }
            get('pagination').hidden = pageCount <= 1;
            get('page').textContent = (page + 1) + ' / ' + pageCount;
            get('prev').disabled = page === 0;
            get('next').disabled = page >= pageCount - 1;
        }
        function renderSelection() {
            get('actions').hidden = !selected;
            if (!selected) {
                get('pdf').replaceChildren();
                get('selection').hidden = false;
                get('open').removeAttribute('href');
                get('edit').removeAttribute('href');
                return;
            }
            const links = documentLinks(current, selected, query);
            get('selection').hidden = true;
            get('open').href = links.pdf;
            get('edit').href = links.edit;
            if (get('pdf').firstElementChild?.getAttribute('src') === links.pdf) return;
            get('pdf').replaceChildren();
            const frame = element('iframe');
            frame.title = 'Original-PDF für Dokument ' + selected.dok_id;
            frame.src = links.pdf;
            get('pdf').append(frame);
        }
        function render(focusId) {
            get('clear').disabled = !query;
            get('breadcrumb').replaceChildren();
            for (let i = 0; i <= current.parts.length; i++) {
                const node = model.nodes.get(pathKey(current.parts.slice(0, i)));
                if (i) get('breadcrumb').append(document.createTextNode(' / '));
                const crumb = button(node.name, 'ea-explorer-crumb', () => navigate(node, null));
                if (node === current) crumb.setAttribute('aria-current', 'location');
                get('breadcrumb').append(crumb);
            }
            renderList(focusId);
            renderSelection();
        }
        function navigate(node, doc, focus = false) {
            current = node;
            selected = doc;
            if (!doc) { query = ''; get('filter').value = ''; }
            page = doc ? Math.floor(listEntries(node, query).findIndex(entry => entry.doc === doc) / pageSize) : 0;
            get('notice').hidden = true;
            updateUrl(true);
            render(focus ? doc?.dok_id : undefined);
        }
        function restore() {
            const state = selectionFromUrl(model, window.location.href);
            current = state.node;
            selected = state.document;
            query = state.query;
            get('filter').value = query;
            page = selected ? Math.floor(listEntries(current, query).findIndex(entry => entry.doc === selected) / pageSize) : 0;
            get('notice').hidden = !state.missing;
            get('notice').textContent = state.missing ? 'Das zuvor ausgewählte Dokument ist nicht mehr im aktuellen Bestand enthalten.' : '';
            updateUrl(false);
            render();
        }
        get('prev').addEventListener('click', () => { page--; renderList(); });
        get('next').addEventListener('click', () => { page++; renderList(); });
        function applyFilter() {
            query = get('filter').value;
            page = 0;
            if (selected && !listEntries(current, query).some(entry => entry.doc === selected)) selected = null;
            get('notice').hidden = true;
            get('clear').disabled = !query;
            updateUrl(false);
            renderList();
            renderSelection();
        }
        get('filter').addEventListener('input', applyFilter);
        get('clear').addEventListener('click', () => {
            get('filter').value = '';
            applyFilter();
            get('filter').focus();
        });
        window.addEventListener('popstate', restore);
        restore();
    }

    const api = {buildTree, selectionFromUrl, viewUrl, documentLinks, listEntries, mount};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (root.document) mount(root.document, root);
})(typeof window !== 'undefined' ? window : globalThis);
