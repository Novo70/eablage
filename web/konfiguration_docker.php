<?php
// Konfiguration für compose.fresh.yaml. Zugangsdaten kommen ausschließlich aus
// den Container-Umgebungsvariablen; diese Datei enthält keine lokalen Secrets.
error_reporting(E_ALL);

foreach (['DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME'] as $name) {
    if (getenv($name) === false || getenv($name) === '') {
        error_log('eAblage: fehlende Umgebungsvariable ' . $name);
        http_response_code(500);
        die('Die Anwendungskonfiguration konnte nicht geladen werden.');
    }
}

define('MYSQL_HOST', getenv('DB_HOST'));
define('MYSQL_BENUTZER', getenv('DB_USER'));
define('MYSQL_KENNWORT', getenv('DB_PASSWORD'));
define('MYSQL_DATENBANK', getenv('DB_NAME'));

try {
    $dsn = 'mysql:host=' . MYSQL_HOST . ';dbname=' . MYSQL_DATENBANK . ';charset=utf8mb4';
    $db_config = new PDO($dsn, MYSQL_BENUTZER, MYSQL_KENNWORT);
    $db_config->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $query = $db_config->query('SELECT param_key, param_value FROM parameter');
    $config = $query->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    error_log('eAblage Konfigurationsfehler: ' . $e->getMessage());
    http_response_code(500);
    die('Die Anwendungskonfiguration konnte nicht geladen werden.');
}

define('PFAD_INBOX', $config['pfadAblageNeu_linux']);
define('PFAD_ARCHIV', $config['pfadAblage_linux']);
define('PFAD_META', $config['pfadMetadaten_linux']);
define('DOKID_STARTWERT', $config['dokStartwert']);
define('DB_TABELLE_ARCHIV', 'dokumente');
