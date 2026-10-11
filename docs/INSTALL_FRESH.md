# Isolierte Neuinstallation und Testbetrieb

Diese Anleitung beschreibt eine vereinfachte Installation einer eAblage-4.3.0-Instanz mit integrierter MariaDB (z. B. als zweite Testinstanz, aber auch als produktive Installation möglich, wenn kein direkter Zugriff auf die Datenbank benötigt wird). Eine evtl. bestehende eAblage wird nicht aktualisiert und verwendet weiterhin `compose.yaml` und `.env`. Der Test nutzt `compose.fresh.yaml`, eine eigene MariaDB und einen eigenen Datenordner.

Voraussetzung: Docker mit Compose, SSH-Zugang und ein vollständiger, zusammengehöriger Projektstand einschließlich `compose.fresh.yaml`, `.env.fresh.example`, `sql/init_docker_paths.sql` und `web/konfiguration_docker.php`. Beim ersten Start werden Images und Python-Pakete aus dem Internet geladen.

## 1. Projekt getrennt ablegen

Kopiere den Projektstand nach `/volume1/docker/eablage_fresh`. Die Dateien `compose.fresh.yaml`, `web/Dockerfile`, `backend/Dockerfile` und `sql/schema.sql` müssen dort vorhanden sein. Kopiere **keine** `.env`, `web/konfiguration.php`, PDFs oder Datenbankdateien einer evtl. bereits laufenden Installation. Verwende keinen bereits benutzten Datenordner.

## 2. Vier Werte eintragen

Per SSH auf dem NAS:

```bash
cd /volume1/docker/eablage_fresh
cp .env.fresh.example .env.fresh
```

Bearbeite `.env.fresh`: `EABLAGE_WEB_PORT` muss frei sein (Beispiel `8084`), `EABLAGE_DATA_PATH` muss auf einen **neuen, leeren** Ordner zeigen (Beispiel `/volume1/eablage-fresh-data`). Ersetze die beiden Passwort-Platzhalter durch verschiedene starke Passwörter. Dieser eine Dateieintrag für `EABLAGE_DB_PASSWORD` wird an Web, Backend und MariaDB übergeben; es gibt keine zweite PHP-Konfiguration mit Passwort.

```bash
sudo chmod 600 .env.fresh
```

Bei UGOS-ACLs muss der Docker-Dienst die Projektdateien lesen können. Lege für den Test die Datenordner an (bei einem anderen Datenpfad den Befehl entsprechend anpassen):

```bash
sudo mkdir -p /volume1/eablage-fresh-data/{inbox,archiv,meta,export,backup}
sudo chown -R 33:33 /volume1/eablage-fresh-data
sudo chmod 755 /volume1/eablage-fresh-data /volume1/eablage-fresh-data/{inbox,archiv,meta,export,backup}
sudo chmod 755 /volume1/docker/eablage_fresh /volume1/docker/eablage_fresh/web
```

Die UID 33 ist der Webbenutzer und die voreingestellte Backend-Identität. Für einen anderen Datenpfad oder andere NAS-Rechte müssen die Schreibrechte entsprechend eingerichtet werden. Keine pauschalen `777`-Rechte setzen.

## 3. Starten

Alle folgenden Compose-Befehle im Projektverzeichnis ausführen. **Immer** dieselben drei Optionen verwenden; so bleibt die Testinstanz von der evtl. laufenden Installation getrennt:

```bash
cd /volume1/docker/eablage_fresh
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage-fresh config --quiet
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage-fresh up -d --build
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage-fresh ps
```

Beim ersten Start erstellt MariaDB die Datenbank, importiert `schema.sql`, `seed.sql` und anschließend die fünf internen Docker-Pfade. Das Datenbank-Volume gehört nur zum Compose-Projekt `eablage-fresh`. Die Weboberfläche sollte unter `http://NAS-IP:8084` erreichbar sein (Port gegebenenfalls an `.env.fresh` anpassen). Bei einer NAS-Firewall den Port für den gewünschten Test-Client freigeben. Die Anwendung nicht ohne zusätzliche Zugangssicherung ins Internet stellen.

## 4. Erstinstallation prüfen

Die Tabellen und Pfade prüfen:

```bash
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage-fresh exec mariadb sh -c 'mariadb -u "$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SHOW TABLES; SELECT param_key, param_value FROM parameter;"'
```

Erwartet werden unter anderem `dokumente`, `parameter`, `/data/archiv/`, `/data/meta/` und `/data/export/`. Anschließend im Browser eine unkritische PDF hochladen, klassifizieren, archivieren und über die Suche wieder öffnen. Prüfe die PDF unter `archiv` und die JSON-Metadatei unter `meta`; teste danach Export und Sicherung. Die laufende Instanz parallel öffnen und kontrollieren, dass dort keine Test-PDF erscheint.

Bei Problemen die Logs lesen:

```bash
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage-fresh logs mariadb --tail 100
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage-fresh logs eablage-web eablage-backend eablage-worker --tail 100
```

**Wichtig:** Die SQL-Initialisierung läuft nur bei einem erstmals angelegten, leeren Datenbank-Volume. Ein erneuter Start importiert nichts erneut. Ändere Passwörter nicht einfach in `.env.fresh`, nachdem MariaDB bereits initialisiert wurde; der angelegte Datenbankbenutzer behält sein bisheriges Passwort.

Zum Anhalten ausschließlich die Testinstanz ansprechen:

```bash
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage-fresh down
```

Dieser Befehl behält Datenordner und Datenbank-Volume. `down -v` würde das Test-Datenbank-Volume entfernen und ist nur nach bewusster Sicherung beziehungsweise Verwerfung der Testdaten sinnvoll. Die bestehende Installation wird mit diesen Befehlen nicht angesprochen.

## 5. Installation entfernen

Diese Schritte löschen **nur nach Prüfung der Pfade** die Testdokumente und
die Testdatenbank. Im Projektverzeichnis zunächst die Testkonfiguration und
den Compose-Projektnamen kontrollieren:

```bash
cd /volume1/docker/eablage_fresh
sed -n '/^EABLAGE_DATA_PATH=/p' .env.fresh
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage-fresh ps
```

Wenn der Datenpfad tatsächlich `/volume1/eablage-fresh-data` lautet und die
Container zur Testinstallation gehören, das Testprojekt einschließlich seines
MariaDB-Volumes und der eigens gebauten Images entfernen:

```bash
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage-fresh down -v --rmi local
```

`-v` entfernt das Datenbank-Volume der Testinstanz. Anschließend die beiden
**zuvor geprüften** Verzeichnisse entfernen:

```bash
sudo rm -rf -- /volume1/eablage-fresh-data
sudo rm -rf -- /volume1/docker/eablage_fresh
```

Bei einem anderen Wert von `EABLAGE_DATA_PATH` den ersten Löschpfad nur nach
erneuter Prüfung anpassen. Das allgemein heruntergeladene Image `mariadb:11.4`
kann im Docker-Cache bleiben; es enthält keine Daten dieser Installation.

Diese Compose-Datei kann auch längerfristig eine eigenständige Instanz betreiben. Projektname, Port und Datenpfad müssen dabei eindeutig bleiben. Regelmäßige Updates und Sicherungen sind wie bei jeder Installation erforderlich: [Aktualisierung](UPDATE.md), [Sicherung und Wiederherstellung](SICHERUNG.md).
