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
?>
<nav class="ea-navbar">
    <div class="ea-navbar__inner">
        <a href="eingang.php" class="ea-brand">
            <img src="ea_logo4.png" alt="" class="ea-brand__logo" onerror="this.style.display='none'">
            <span class="ea-brand__text"></span>
            <span class="ea-brand__version"></span>
        </a>
        <div class="ea-dropdown">
            <button class="ea-dropdown__toggle" id="eaMenuBtn" aria-label="Menü öffnen" type="button">
                <span></span>
                <span></span>
                <span></span>
            </button>
            <div class="ea-dropdown__menu" id="eaMenuDropdown">
                <a href="index.php" class="ea-dropdown__item">🔍 Suche</a>
                <a href="explorer.php" class="ea-dropdown__item">📂 Ablage-Explorer</a>
                <a href="eingang.php" class="ea-dropdown__item">📥 Eingangsbearbeitung</a>
                <a href="papierkorb.php" class="ea-dropdown__item">🗑️ Papierkorb</a>
                <a href="stammdaten.php" class="ea-dropdown__item">🗂️ Stammdaten</a>
                <a href="autokat_regeln.php" class="ea-dropdown__item">🧩 Klassifizierungsregeln</a>
                <a href="gesamtexport.php" class="ea-dropdown__item">📦 Export &amp; Datensicherung</a>
                <a href="protokoll.php" class="ea-dropdown__item">📋 Ereignisprotokoll</a>
                <a href="<?=htmlspecialchars($eaSettingsLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?>" class="ea-dropdown__item">⚙️ Einstellungen</a>
            </div>
        </div>
    </div>
</nav>

<script>
// Robuster Umschalter für das Menü
document.addEventListener('DOMContentLoaded', function() {
    const btn = document.getElementById('eaMenuBtn');
    const menu = document.getElementById('eaMenuDropdown');

    if (btn && menu) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            menu.classList.toggle('is-open');
            btn.classList.toggle('is-active');
        });

        // Schließen bei Klick außerhalb
        document.addEventListener('click', function(e) {
            if (!menu.contains(e.target) && !btn.contains(e.target)) {
                menu.classList.remove('is-open');
                btn.classList.remove('is-active');
            }
        });
    }
});
</script>
