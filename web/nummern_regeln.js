(() => {
    const root = document.getElementById('number-rule-editor'); if (!root) return;
    const hidden = document.getElementById('number_rules_json');
    const kinds = JSON.parse(document.getElementById('rule-number-kinds').textContent);
    const rows = root.querySelector('[data-rule-number-rows]');
    function sync() {
        hidden.value = JSON.stringify([...rows.children].map(row => Object.fromEntries(
            [...row.querySelectorAll('[data-key]')].map(input => [input.dataset.key, input.dataset.key === 'MaxLaenge' ? Number(input.value) : input.value]))));
    }
    function add(n = {}) {
        const row = document.createElement('fieldset'); row.className = 'form-group';
        const fields = [['NummernartID','Nummernart'],['Startanker','Startanker'],['Endmodus','Enderkennung'],['Endanker','Endanker'],['MaxLaenge',n.Endmodus === 'legacy' ? 'Bisherige maximale Länge' : 'Maximale Länge (0 = unbegrenzt)'],['Festwert','Festwert (ohne Startanker)']];
        if (n.Endmodus === 'legacy') fields.push(['Praefix','Bisheriges Anzeigepräfix']);
        for (const [key, label] of fields) {
            const wrap = document.createElement('label'); wrap.textContent = label;
            let input;
            if (key === 'NummernartID' || key === 'Endmodus') {
                input = document.createElement('select');
                const options = key === 'NummernartID' ? [{id:'',Bezeichnung:'Bitte wählen'},...kinds] : [
                    {id:'zeile',Bezeichnung:'Zeilenende'},{id:'leerzeichen',Bezeichnung:'Nächstes Leerzeichen'},{id:'anker',Bezeichnung:'Endanker'}];
                if (key === 'Endmodus' && n.Endmodus === 'legacy') options.push({id:'legacy',Bezeichnung:'Bisherige Erkennung (übernommen)'});
                for (const option of options) input.add(new Option(option.Bezeichnung, String(option.id)));
            } else { input = document.createElement('input'); input.type = key === 'MaxLaenge' ? 'number' : 'text'; input.maxLength = 255; if (key === 'MaxLaenge') { input.min = '0'; input.max = '10000'; input.step = '1'; } }
            input.className = 'form-control'; input.dataset.key = key;
            input.value = String(n[key] ?? (key === 'Endmodus' ? 'zeile' : key === 'MaxLaenge' ? 0 : ''));
            input.addEventListener('input',sync); input.addEventListener('change',sync);
            wrap.append(input); row.append(wrap);
        }
        for (const key of ['Praefix']) if (!row.querySelector('[data-key="'+key+'"]')) {
            const input = document.createElement('input'); input.type = 'hidden'; input.dataset.key = key; input.value = ''; row.append(input);
        }
        const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-default'; remove.textContent = 'Entfernen';
        remove.addEventListener('click',()=>{row.remove();sync();}); row.append(remove); rows.append(row);
    }
    for (const n of JSON.parse(hidden.value)) add(n);
    root.querySelector('[data-rule-number-add]').addEventListener('click',()=>{add();sync();});
})();
