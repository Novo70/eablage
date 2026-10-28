<?php
$eaCurrentUri = $_SERVER['REQUEST_URI'] ?? 'dokumente.php';
$eaCurrentPath = parse_url($eaCurrentUri, PHP_URL_PATH);
$eaCurrentPage = basename((string)$eaCurrentPath);
$eaCurrentQuery = parse_url($eaCurrentUri, PHP_URL_QUERY);
$eaSettingsReturn = $eaCurrentPage !== '' ? $eaCurrentPage : 'dokumente.php';
if ($eaCurrentQuery !== null && $eaCurrentQuery !== '') {
    $eaSettingsReturn .= '?' . $eaCurrentQuery;
}
$eaSettingsLink = 'einstellungen.php?return=' . rawurlencode($eaSettingsReturn);
$eaPrimaryLinks = [
    'index.php' => 'Suche',
    'explorer.php' => 'Ablage',
    'eingang.php' => 'Eingang',
];
$eaActivePage = match ($eaCurrentPage) {
    'dokumente.php' => 'index.php',
    'bearbeiten.php' => match (basename((string)parse_url((string)($_GET['return'] ?? ''), PHP_URL_PATH))) {
        'explorer.php' => 'explorer.php',
        'verarbeiten.php' => 'eingang.php',
        default => 'index.php',
    },
    'verarbeiten.php', 'speichern.php' => 'eingang.php',
    default => $eaCurrentPage,
};
?>
<nav class="ea-navbar" aria-label="Hauptnavigation">
    <div class="ea-navbar__inner">
        <a href="eingang.php" class="ea-brand" aria-label="eAblage – zum Eingang">
            <img src="ea_logo4.png" alt="" class="ea-brand__logo" onerror="this.style.display='none'">
        </a>
        <div class="ea-navbar__links">
            <?php foreach ($eaPrimaryLinks as $href => $label): ?>
                <a href="<?=$href?>" class="ea-navbar__link" <?=$eaActivePage === $href ? 'aria-current="page"' : ''?>><?=$label?></a>
            <?php endforeach; ?>
        </div>
        <div class="ea-dropdown">
            <button class="ea-dropdown__toggle <?=!isset($eaPrimaryLinks[$eaActivePage]) ? 'is-current' : ''?>" id="eaMenuBtn" aria-label="Menü öffnen" aria-expanded="false" aria-controls="eaMenuDropdown" type="button">
                <span class="ea-menu-label">Verwaltung</span>
                <span class="ea-menu-icon" aria-hidden="true"><span></span><span></span><span></span></span>
            </button>
            <div class="ea-dropdown__menu" id="eaMenuDropdown">
                <?php foreach ($eaPrimaryLinks as $href => $label): ?>
                    <a href="<?=$href?>" class="ea-dropdown__item ea-menu-primary" <?=$eaActivePage === $href ? 'aria-current="page"' : ''?>><?=$label?></a>
                <?php endforeach; ?>
                <?php foreach ([
                    'papierkorb.php' => '🗑️ Papierkorb',
                    'stammdaten.php' => '🗂️ Stammdaten',
                    'autokat_regeln.php' => '🧩 Klassifizierungsregeln',
                    'gesamtexport.php' => '📦 Export & Datensicherung',
                    'protokoll.php' => '📋 Ereignisprotokoll',
                    $eaSettingsLink => '⚙️ Einstellungen',
                ] as $href => $label):
                    $active = $eaCurrentPage === parse_url($href, PHP_URL_PATH)
                        || ($href === 'autokat_regeln.php' && $eaCurrentPage === 'autokat_regel_bearbeiten.php')
                        || ($href === 'gesamtexport.php' && in_array($eaCurrentPage, ['export.php','datenbank_sicherung.php','datenbank_wiederherstellung.php','archiv_wartung.php'], true)); ?>
                    <a href="<?=htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" class="ea-dropdown__item" <?=$active ? 'aria-current="page"' : ''?>><?=htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></a>
                <?php endforeach; ?>
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
            if (e.key === 'Escape' && btn.getAttribute('aria-expanded') === 'true') {
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
