# Neuinstallation (getestet auf einem UGREEN NAS)

Diese Anleitung beschreibt die einmalige Installation von eAblage auf einem UGREEN NAS mit UGOS Pro (das Verfahren sollte auch in vergleichbaren Umgebungen funktionieren). Sie richtet eine eigene MariaDB, die PHP-Oberfläche, das Python-Backend und den Inbox-Worker in Docker ein.

**Dokumentationsstand:** eAblage 4.3.0, auf `develop` vorbereitet. Für die Installation einen vollständigen, zusammengehörigen Projektstand verwenden und dessen Commit dokumentieren. Für den regulären Produktivbetrieb einen freigegebenen Stand wählen; `develop` zunächst getrennt testen. Die Beispiele setzen ein NAS mit Docker und Docker Compose, SSH-Zugang sowie ausreichend Speicherplatz voraus. Alle Befehle werden per SSH **auf dem NAS** ausgeführt. Alle Pfade, Instanznamen und Passwörter sind neutrale Beispiele und müssen bei abweichender Installation einheitlich angepasst werden.

## Überblick und Beispielpfade

| Zweck | Pfad auf dem NAS | Pfad im Container |
| --- | --- | --- |
| Anwendungscode und lokale Konfiguration | `/volume1/docker/eablage` | – |
| MariaDB-Compose-Datei | `/volume1/docker/mariadb` | – |
| PDFs, Metadaten, Export und Sicherungen | `/volume1/eablage-data` | `/data` |
| MariaDB-Daten | Docker-Volume `mariadb_data` des Datenbank-Stacks | `/var/lib/mysql` |

Die Weboberfläche ist später über `http://NAS-IP:8082` erreichbar. MariaDB bekommt **keinen** vom NAS aus erreichbaren Port. Die Container kommunizieren über das gemeinsame Docker-Netzwerk `database`.

## 1. Voraussetzungen prüfen und Programmdateien bereitstellen

Docker beziehungsweise die Container-Funktion in UGOS Pro muss installiert und gestartet sein. Aktiviere SSH für die Einrichtung und prüfe nach der Anmeldung:

```bash
sudo docker version
sudo docker compose version
```

Besorge den vollständigen gewünschten Projektstand aus dem eAblage-Repository. Dafür benötigst du Leserechte oder eine vom Eigentümer bereitgestellte ZIP-Datei. Notiere den verwendeten Commit. Entpacke **den Inhalt** des Projekts nach `/volume1/docker/eablage`. Dort müssen anschließend `compose.yaml`, `.env.example`, `web/`, `backend/` und `sql/` direkt liegen. Kopiere keine Zugangsdaten aus einer anderen Installation mit.

```bash
cd /volume1/docker/eablage
ls compose.yaml .env.example web/konfiguration_example.php sql/schema.sql sql/seed.sql
```

Falls du einen anderen Speicherort für die Nutzdaten möchtest, ersetze `/volume1/eablage-data` in den späteren Befehlen und in `.env` einheitlich. Der Pfad für den Anwendungscode kann ebenfalls abweichen; passe dann `EABLAGE_CONFIG_PATH` an.

## 2. Gemeinsames Docker-Netzwerk anlegen

```bash
sudo docker network create database
```

Falls Docker meldet, dass das Netzwerk bereits existiert, kannst du es verwenden. Die eAblage-Compose-Datei erwartet genau diesen Netzwerknamen.

## 3. MariaDB einrichten

Lege `/volume1/docker/mariadb` an. Erstelle darin die Datei `compose.yaml` mit folgendem Inhalt:

```yaml
services:
  mariadb:
    image: mariadb:11.4
    restart: unless-stopped
    environment:
      MARIADB_ROOT_PASSWORD: ${MARIADB_ROOT_PASSWORD:?Root-Passwort fehlt}
      MARIADB_DATABASE: eablage
      MARIADB_USER: eablage
      MARIADB_PASSWORD: ${EABLAGE_DB_PASSWORD:?Anwendungspasswort fehlt}
      TZ: Europe/Berlin
    volumes:
      - mariadb_data:/var/lib/mysql
      - ../eablage/sql/schema.sql:/docker-entrypoint-initdb.d/01-schema.sql:ro
      - ../eablage/sql/seed.sql:/docker-entrypoint-initdb.d/02-seed.sql:ro
    networks:
      database:
        aliases:
          - mariadb

volumes:
  mariadb_data:

networks:
  database:
    external: true
```

Die beiden SQL-Dateien werden beim **allerersten Start mit leerem Datenbank-Volume** in dieser Reihenfolge importiert. Der per `MARIADB_USER` angelegte Anwendungsbenutzer erhält Rechte an der Datenbank `eablage`. Die Tabellen werden mit InnoDB und `utf8mb4` angelegt. Starte nicht mit einer bereits genutzten Datenbank oder einem vorhandenen MariaDB-Volume.

Erstelle daneben `/volume1/docker/mariadb/.env`:

```env
MARIADB_ROOT_PASSWORD=HIER_EIN_EIGENES_STARKES_ROOT_PASSWORT
EABLAGE_DB_PASSWORD=HIER_EIN_ANDERES_STARKES_ANWENDUNGSPASSWORT
```

Die Beispieltexte sind vollständig zu ersetzen. Verwende für die erste Einrichtung Passwörter ohne `$`, `#`, Leerzeichen oder Anführungszeichen, damit sie unverändert von Compose gelesen werden. Halte die `.env` nur für Administratoren lesbar und gib sie nicht zusammen mit dem Projekt weiter:

```bash
sudo chmod 600 /volume1/docker/mariadb/.env
cd /volume1/docker/mariadb
sudo docker compose config --quiet
sudo docker compose up -d
sudo docker compose ps
```

Prüfe, ob der Import abgeschlossen ist. Dieser Befehl verwendet die Zugangsdaten aus dem Datenbankcontainer und sollte unter anderem `parameter` und `dokumente` ausgeben:

```bash
sudo docker compose exec mariadb sh -c 'mariadb -u "$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SHOW TABLES; SELECT COUNT(*) AS parameter_anzahl FROM parameter;"'
```

Falls noch keine Tabellen sichtbar sind, prüfe `sudo docker compose logs mariadb --tail 100`. Nach der Ersteinrichtung bleiben Daten im benannten Volume erhalten. Ein erneuter Containerstart importiert die SQL-Dateien **nicht** erneut.

## 4. Datenverzeichnisse anlegen und Rechte setzen

```bash
sudo mkdir -p /volume1/eablage-data/{inbox,archiv,meta,export,backup}
sudo chown -R 33:33 /volume1/eablage-data
sudo chmod 755 /volume1/eablage-data /volume1/eablage-data/{inbox,archiv,meta,export,backup}
```

Im Webcontainer läuft Apache als `www-data` (UID 33); das Backend ist ebenfalls auf UID/GID 33 eingestellt. Deshalb muss dieser Benutzer die Ordner betreten und Dateien darin anlegen können. UGOS-ACLs können die Unix-Rechte zusätzlich einschränken. Falls Schreibtests später scheitern, erteile dem entsprechenden Benutzer beziehungsweise der Gruppe gezielt Lese- und Schreibrechte auf diese fünf Ordner; verwende keine pauschalen `777`-Rechte.

## 5. eAblage konfigurieren

Wechsle in das Projektverzeichnis und erstelle die lokale `.env` aus der Vorlage:

```bash
cd /volume1/docker/eablage
cp .env.example .env
```

Trage in `.env` die folgenden Werte ein. Du kannst die Beispieldatei entsprechend bearbeiten; jeder Schlüssel soll nur einmal vorkommen:

```env
EABLAGE_INSTANCE=prod
EABLAGE_WEB_PORT=8082
EABLAGE_CONFIG_PATH=/volume1/docker/eablage/web/konfiguration.php
EABLAGE_DATA_PATH=/volume1/eablage-data
EABLAGE_META_PATH=/volume1/eablage-data/meta
EABLAGE_META_CONTAINER_PATH=/data/meta
EABLAGE_BACKEND_UID=33
EABLAGE_BACKEND_GID=33
EABLAGE_DB_HOST=mariadb
EABLAGE_DB_NAME=eablage
EABLAGE_DB_USER=eablage
EABLAGE_DB_PASSWORD=DAS_ANWENDUNGSPASSWORT_AUS_DER_MARIADB_ENV
EABLAGE_BACKEND_URL=http://backend:8000
```

`EABLAGE_DB_PASSWORD` muss **exakt** dem `EABLAGE_DB_PASSWORD` in `/volume1/docker/mariadb/.env` entsprechen. `mariadb` und `backend` sind interne Docker-Namen; trage hier weder die NAS-IP noch `localhost` ein.

Erstelle danach die lokale PHP-Konfiguration:

```bash
cp web/konfiguration_example.php web/konfiguration.php
```

Bearbeite **nur die vier Datenbankwerte** am Anfang von `web/konfiguration.php` wie folgt; der übrige Inhalt der Vorlage bleibt erhalten:

```php
define('MYSQL_HOST', 'mariadb');
define('MYSQL_BENUTZER', 'eablage');
define('MYSQL_KENNWORT', 'DAS_ANWENDUNGSPASSWORT_AUS_DER_MARIADB_ENV');
define('MYSQL_DATENBANK', 'eablage');
```

`MYSQL_KENNWORT` muss mit dem Passwort in **beiden** `.env`-Dateien übereinstimmen. Die Vorlage enthält bereits `DB_TABELLE_ARCHIV = dokumente`; ändere den Tabellennamen nicht. Verwende beim ersten Test ein Passwort ohne Apostroph, da es sonst in PHP maskiert werden müsste.

Schütze die Zugangsdaten und mache die Verzeichnisse für Docker betretbar. Die PHP-Datei gehört dabei dem Containerbenutzer `www-data` (UID/GID 33), damit er sie trotz Modus `600` lesen kann:

```bash
sudo chmod 600 .env
sudo chown 33:33 web/konfiguration.php
sudo chmod 600 web/konfiguration.php
sudo chmod 755 /volume1/docker/eablage /volume1/docker/eablage/web
```

Falls die Datei im Container dennoch nicht lesbar ist, prüfe Eigentümer und UGOS-ACLs. Bei einer späteren Bearbeitung durch einen NAS-Benutzer gegebenenfalls mit `sudo` arbeiten und anschließend Eigentümer und Modus erneut setzen.

## 6. Installationspfade in der Datenbank setzen

Der Seed enthält absichtlich Platzhalter für die fünf installationsabhängigen Verzeichnisse. Setze die **Containerpfade**, nicht die NAS-Pfade. Führe im Projektverzeichnis diesen Befehl aus:

```bash
cd /volume1/docker/eablage
sudo docker compose -f ../mariadb/compose.yaml --env-file ../mariadb/.env exec -T mariadb sh -c 'mariadb -u "$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' <<'SQL'
UPDATE parameter SET param_value = '/data/inbox/'  WHERE param_key = 'pfadAblageNeu_linux';
UPDATE parameter SET param_value = '/data/archiv/' WHERE param_key = 'pfadAblage_linux';
UPDATE parameter SET param_value = '/data/meta/'   WHERE param_key = 'pfadMetadaten_linux';
UPDATE parameter SET param_value = '/data/export/' WHERE param_key = '1_expVerzeichnis_linux';
UPDATE parameter SET param_value = '/data/backup/' WHERE param_key = 'pfadDatensicherung_linux';
SQL
```

Die abschließenden `/` sind beabsichtigt. Der Backend-Mount für `meta` in `.env` muss zu `/data/meta/` passen.

## 7. Anwendung bauen und starten

```bash
cd /volume1/docker/eablage
sudo docker compose config --quiet
sudo docker compose build
sudo docker compose up -d
sudo docker compose ps
```

`eablage-web`, `eablage-backend` und `eablage-worker` müssen laufen. Der erste Build benötigt eine Internetverbindung zum Laden der Basisimages und Python-Pakete. Rufe im selben lokalen Netzwerk `http://NAS-IP:8082` auf; ersetze `NAS-IP` durch die tatsächliche Adresse des NAS. Falls die NAS-Firewall aktiv ist, erlaube TCP-Port 8082 nur für die gewünschten Clients. Die Anwendung enthält in dieser Installation keine vorgeschaltete Anmeldung; veröffentliche den Port nicht direkt im Internet.

## 8. Funktionstest

Prüfe zunächst die interne Verbindung vom Webcontainer zum Backend und vom Backend zur Datenbank:

```bash
cd /volume1/docker/eablage
sudo docker compose exec eablage-web php -r "echo file_get_contents('http://backend:8000/health');"
sudo docker compose exec eablage-backend python -c "from app.db.database import get_connection; c=get_connection(); print(c.is_connected()); c.close()"
```

Der erste Befehl soll JSON mit `status: ok` liefern, der zweite `True`. Teste anschließend in der Oberfläche mit einer unkritischen PDF:

1. Eine PDF hochladen oder nach `/volume1/eablage-data/inbox` kopieren.
2. Den Eingang öffnen, das Dokument klassifizieren und archivieren.
3. Das Dokument über die Suche oder den Ablage-Explorer wieder öffnen.
4. Prüfen, ob eine PDF in `archiv` und eine JSON-Metadatei in `meta` angelegt wurden.
5. Optional Export und Datenbanksicherung aus der Oberfläche testen; deren Ziele sind `export` und `backup`.

Die in `seed.sql` enthaltenen Kategorien und Dokumenttypen sind Beispieldaten. Sie können nach erfolgreicher Installation in der Oberfläche angepasst werden.

## Wenn ein Schritt scheitert

| Fehler | Erste Prüfung |
| --- | --- |
| `network database not found` | Schritt 2; `sudo docker network ls`. |
| Datenbank startet, aber Tabellen fehlen | Datenbank-Logs und Mounts der beiden SQL-Dateien prüfen. Initialisierung läuft nur bei leerem Volume. |
| `Access denied` bei Datenbankzugriff | Anwendungspasswort in beiden `.env`-Dateien und `web/konfiguration.php` vergleichen; `mariadb` als Host prüfen. Spätere Änderungen der MariaDB-Initialisierungsvariablen ändern einen bereits angelegten Benutzer nicht automatisch. |
| Konfiguration nicht lesbar | Pfad, Rechte und UGOS-ACL für `web/konfiguration.php` sowie Betretbarkeit der Elternordner prüfen. |
| Archivierung oder Export meldet `Permission denied` | Schreibrechte/ACL für UID 33 in den Datenordnern und die fünf Datenbankpfade prüfen. |
| Backend nicht erreichbar | `EABLAGE_BACKEND_URL=http://backend:8000` und Status/Logs von `eablage-backend` prüfen. |

Logs der Anwendung:

```bash
cd /volume1/docker/eablage
sudo docker compose logs eablage-web --tail 100
sudo docker compose logs eablage-backend --tail 100
```

Logs der Datenbank:

```bash
cd /volume1/docker/mariadb
sudo docker compose logs mariadb --tail 100
```

**Sicherung:** Für einen dauerhaften Betrieb gehören MariaDB-Datenbestand, PDFs und JSON-Metadaten in ein gemeinsames Sicherungskonzept. Das benannte Docker-Volume allein ist kein Backup. Bewahre außerdem die lokalen Konfigurationsdateien und Zugangsdaten sicher auf.

Weitere Einrichtung und Parameter: [Konfiguration](KONFIGURATION.md). Nach der Einrichtung die [Funktionsprüfung](ENTWICKLUNG.md#funktionsprüfung-in-einer-getrennten-instanz) durchführen und eine [zusammengehörige Sicherung](SICHERUNG.md) einrichten.

Der Worker bereitet die Inbox automatisch vor. Status und Konfiguration: [Stapelverarbeitung](STAPELVERARBEITUNG.md).
