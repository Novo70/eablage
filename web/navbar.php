<?php
require_once __DIR__ . '/icons.php';
$eaCurrentUri = $_SERVER['REQUEST_URI'] ?? 'dokumente.php';
$eaCurrentPath = parse_url($eaCurrentUri, PHP_URL_PATH);
$eaCurrentPage = basename((string)$eaCurrentPath);
$eaCurrentQuery = parse_url($eaCurrentUri, PHP_URL_QUERY);
$eaSettingsReturn = $eaCurrentPage !== '' ? $eaCurrentPage : 'dokumente.php';
if ($eaCurrentQuery !== null && $eaCurrentQuery !== '') {
    $eaSettingsReturn .= '?' . $eaCurrentQuery;
}
$eaSettingsLink = 'einstellungen.php?return=' . rawurlencode($eaSettingsReturn);
$eaNavIcons = [
    'index.php' => 'search',
    'explorer.php' => 'folder',
    'vorgaenge.php' => 'link',
    'eingang.php' => 'inbox',
    'papierkorb.php' => 'trash',
    'stammdaten.php' => 'table',
    'autokat_regeln.php' => 'rules',
    'gesamtexport.php' => 'export',
    'protokoll.php' => 'clipboard',
    'einstellungen.php' => 'settings',
];
$eaNavIcon = static function(string $href) use ($eaNavIcons): string {
    return eablage_icon($eaNavIcons[parse_url($href, PHP_URL_PATH)] ?? 'document', 'ea-nav-icon');
};
$eaPrimaryLinks = [
    'index.php' => 'Suche',
    'explorer.php' => 'Ablage',
    'vorgaenge.php' => 'Vorgänge',
    'eingang.php' => 'Eingang',
];
$eaActivePage = match ($eaCurrentPage) {
    'dokumente.php', 'ansehen.php' => 'index.php',
    'bearbeiten.php' => match (basename((string)parse_url((string)($_GET['return'] ?? ''), PHP_URL_PATH))) {
        'explorer.php' => 'explorer.php',
        'verarbeiten.php' => 'eingang.php',
        'vorgaenge.php' => 'vorgaenge.php',
        default => 'index.php',
    },
    'verarbeiten.php', 'speichern.php' => 'eingang.php',
    'vorgang_anlegen.php' => 'vorgaenge.php',
    default => $eaCurrentPage,
};
?>
<script>
// Experimentelle Ansicht pro Browser; die Standardansicht benötigt keine Zusatz-CSS.
(() => {
    const requested = new URLSearchParams(window.location.search).get('layout');
    let layout = 'touch';
    try {
        const saved = window.localStorage.getItem('eablage.layout');
        if (saved === 'desktop') layout = saved;
    } catch (_) { /* Bei gesperrtem Speicher bleibt der Standard aktiv. */ }
    if (requested === 'desktop' || requested === 'touch') {
        layout = requested;
        try { window.localStorage.setItem('eablage.layout', layout); } catch (_) {}
    }
    function applyLayout(value) {
        document.documentElement.dataset.eaLayout = value;
        let stylesheet = document.getElementById('eaDesktopStylesheet');
        if (value === 'desktop' && !stylesheet) {
            stylesheet = document.createElement('link');
            stylesheet.id = 'eaDesktopStylesheet';
            stylesheet.rel = 'stylesheet';
            stylesheet.href = 'desktop.css?v=<?=filemtime(__DIR__ . '/desktop.css')?>';
            document.head.appendChild(stylesheet);
        } else if (value !== 'desktop' && stylesheet) {
            stylesheet.remove();
        }
    }
    applyLayout(layout);
    document.addEventListener('DOMContentLoaded', () => {
        const chooser = document.getElementById('eaLayoutSwitch');
        if (!chooser) return;
        const options = chooser.querySelectorAll('input[name="ea-layout"]');
        const hint = document.getElementById('eaLayoutHint');
        function syncSelection() {
            options.forEach(option => { option.checked = option.value === layout; });
            hint.hidden = layout !== 'desktop';
        }
        syncSelection();
        chooser.hidden = false;
        chooser.addEventListener('change', event => {
            if (!Array.from(options).includes(event.target)) return;
            const value = event.target.value;
            if (value !== 'desktop' && value !== 'touch') return;
            layout = value;
            applyLayout(layout);
            syncSelection();
            try { window.localStorage.setItem('eablage.layout', layout); } catch (_) {}
            // Beim Verlassen der offenen Seitenleiste die Darstellungswahl sichtbar halten.
            const menuButton = document.getElementById('eaMenuBtn');
            if (layout === 'touch' && menuButton?.getAttribute('aria-expanded') === 'false') {
                menuButton.click();
            }
            // Ein alter Testparameter darf die neue Auswahl beim Neuladen nicht ersetzen.
            const url = new URL(window.location.href);
            if (url.searchParams.has('layout')) {
                url.searchParams.delete('layout');
                try { window.history.replaceState(window.history.state, '', url); } catch (_) {}
            }
        });
    });
})();
</script>
<script src="preview_layout.js?v=<?=filemtime(__DIR__ . '/preview_layout.js')?>" defer></script>
<nav class="ea-navbar" aria-label="Hauptnavigation">
    <div class="ea-navbar__inner">
        <a href="eingang.php" class="ea-brand" aria-label="eAblage – zum Eingang">
            <img src="ea_logo4.png" alt="" class="ea-brand__logo" onerror="this.style.display='none'">
        </a>
        <div class="ea-navbar__links">
            <?php foreach ($eaPrimaryLinks as $href => $label): ?>
                <a href="<?=$href?>" class="ea-navbar__link" <?=$eaActivePage === $href ? 'aria-current="page"' : ''?>><?=$eaNavIcon($href)?><span><?=$label?></span></a>
            <?php endforeach; ?>
        </div>
        <div class="ea-dropdown">
            <button class="ea-dropdown__toggle <?=!isset($eaPrimaryLinks[$eaActivePage]) ? 'is-current' : ''?>" id="eaMenuBtn" aria-label="Menü öffnen" aria-expanded="false" aria-controls="eaMenuDropdown" type="button">
                <span class="ea-menu-label">Verwaltung</span>
                <span class="ea-menu-icon" aria-hidden="true"><span></span><span></span><span></span></span>
            </button>
            <div class="ea-dropdown__menu" id="eaMenuDropdown">
                <p class="ea-sidebar-admin-title">Verwaltung</p>
                <?php foreach ($eaPrimaryLinks as $href => $label): ?>
                    <a href="<?=$href?>" class="ea-dropdown__item ea-menu-primary" <?=$eaActivePage === $href ? 'aria-current="page"' : ''?>><?=$eaNavIcon($href)?><span><?=$label?></span></a>
                <?php endforeach; ?>
                <?php foreach ([
                    'papierkorb.php' => 'Papierkorb',
                    'stammdaten.php' => 'Stammdaten',
                    'autokat_regeln.php' => 'Klassifizierungsregeln',
                    'gesamtexport.php' => 'Export & Datensicherung',
                    'protokoll.php' => 'Ereignisprotokoll',
                    $eaSettingsLink => 'Einstellungen',
                ] as $href => $label):
                    $active = $eaCurrentPage === parse_url($href, PHP_URL_PATH)
                        || ($href === 'autokat_regeln.php' && $eaCurrentPage === 'autokat_regel_bearbeiten.php')
                        || ($href === 'gesamtexport.php' && in_array($eaCurrentPage, ['export.php','datenbank_sicherung.php','datenbank_wiederherstellung.php','archiv_wartung.php'], true)); ?>
                    <a href="<?=htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" class="ea-dropdown__item" <?=$active ? 'aria-current="page"' : ''?>><?=$eaNavIcon($href)?><span><?=htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></span></a>
                <?php endforeach; ?>
                <fieldset class="ea-layout-switch" id="eaLayoutSwitch" aria-describedby="eaLayoutHint" hidden>
                    <legend>Darstellung</legend>
                    <div class="ea-layout-switch__options">
                        <label><input type="radio" name="ea-layout" value="touch"><span>Standard</span></label>
                        <label><input type="radio" name="ea-layout" value="desktop"><span>Desktop</span></label>
                    </div>
                    <p class="ea-layout-switch__hint" id="eaLayoutHint" hidden>Die Desktop-Ansicht wird ab 1100 Pixeln Fensterbreite mit Mausbedienung aktiv.</p>
                </fieldset>
            </div>
        </div>
    </div>
</nav>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const btn = document.getElementById('eaMenuBtn');
    const menu = document.getElementById('eaMenuDropdown');

    if (btn && menu) {
        const dropdown = btn.closest('.ea-dropdown');
        const sidebar = () => document.documentElement.dataset.eaLayout === 'desktop'
            && window.matchMedia('(min-width: 1100px) and (hover: hover) and (pointer: fine)').matches;
        function setOpen(open) {
            menu.classList.toggle('is-open', open);
            btn.classList.toggle('is-active', open);
            btn.setAttribute('aria-expanded', String(open));
            btn.setAttribute('aria-label', open ? 'Menü schließen' : 'Menü öffnen');
        }
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            setOpen(btn.getAttribute('aria-expanded') !== 'true');
        });
        document.addEventListener('click', function(e) {
            if (!dropdown.contains(e.target)) setOpen(false);
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !sidebar() && btn.getAttribute('aria-expanded') === 'true') {
                setOpen(false);
                btn.focus();
            }
        });
        document.addEventListener('focusin', function(e) {
            if (!dropdown.contains(e.target)) setOpen(false);
        });
        window.matchMedia('(min-width: 900px)').addEventListener('change', function() { setOpen(false); });
    }
});
</script>



