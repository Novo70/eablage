<?php
// die Konstanten auslagern in eigene Datei
// die dann per require_once ('konfiguration.php');
// geladen wird.

// Damit alle Fehler angezeigt werden
error_reporting(E_ALL);

// Zum Aufbau der Verbindung zur Datenbank
// die Daten erhalten Sie von Ihrem Provider
define ( 'MYSQL_HOST',      'xxx' );

// bei XAMPP ist der MYSQL_Benutzer: root
define ( 'MYSQL_BENUTZER',  'xxx' );
define ( 'MYSQL_KENNWORT',  'xxx' );
// Name der e_ablage-Datenbank (z. B. e_ablage oder e_ablage_test)
define ( 'MYSQL_DATENBANK', 'xxx' );

try {
    // Verbindung aufbauen
    $dsn = "mysql:host=" . MYSQL_HOST . ";dbname=" . MYSQL_DATENBANK . ";charset=utf8mb4";
    $db_config = new PDO($dsn, MYSQL_BENUTZER, MYSQL_KENNWORT);
    $db_config->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Parameter-Tabelle zeilenweise auslesen
    $query = $db_config->query("SELECT param_key, param_value FROM parameter");
    $config = $query->fetchAll(PDO::FETCH_KEY_PAIR);

} catch (PDOException $e) {
    error_log('e_ablage Konfigurationsfehler: ' . $e->getMessage());
    http_response_code(500);
    die('Die Anwendungskonfiguration konnte nicht geladen werden.');
}

// Linux-Verzeichnispfade für Eingang, Archiv und Metadaten definieren
define('PFAD_INBOX',  $config['pfadAblageNeu_linux']);  // Holt '/data/inbox/'
define('PFAD_ARCHIV', $config['pfadAblage_linux']);     // Holt '/data/archiv/'
define('PFAD_META',   $config['pfadMetadaten_linux']);  // Holt '/data/meta/'

// Weitere nützliche Steuerparameter aus der DB mappen
define('DOKID_STARTWERT', $config['dokStartwert']); // Startwert für Dokumenten_ID

// Fester Tabellenname des Anwendungsschemas; kein einstellbarer Parameter.
define('DB_TABELLE_ARCHIV', 'dokumente');
?>