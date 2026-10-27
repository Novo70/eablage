# Installation mit integrierter Datenbank

Diese Anleitung beschreibt die vereinfachte Neuinstallation von eAblage 4.4.0 mit integrierter MariaDB. Weboberfläche, Backend, Worker und Datenbank werden gemeinsam mit `compose.fresh.yaml` eingerichtet. Die Variante eignet sich sowohl für den Produktivbetrieb als auch für eine Testinstanz. Eine separat betriebene MariaDB ist nicht erforderlich.

Alternativ kann eAblage eine [separate Datenbank](INSTALL_STANDARD.md) verwenden. Der Einsatzzweck ist unabhängig von dieser Auswahl. Bei mehreren Instanzen müssen Projektname, Port, Datenbank und Datenverzeichnisse getrennt bleiben; siehe [Mehrere Instanzen betreiben](KONFIGURATION.md#mehrere-instanzen-betreiben).

Die Dateinamen `compose.fresh.yaml` und `.env.fresh` bezeichnen diese Installationsvariante, keinen Testbetrieb. Für bestehende Installationen gelten die [Update-Schritte](UPDATE.md).

Voraussetzung: Docker mit Compose, SSH-Zugang und ein vollständiger, zusammengehöriger Projektstand einschließlich `compose.fresh.yaml`, `.env.fresh.example`, `sql/init_docker_paths.sql` und `web/konfiguration_docker.php`. Beim ersten Start werden Images und Python-Pakete aus dem Internet geladen.

## 1. Projekt ablegen

Kopiere den Projektstand in ein neues Projektverzeichnis, im Beispiel `/volume1/docker/eablage`. Wird dieses Verzeichnis bereits für eine andere Instanz verwendet, wähle ein anderes und passe die folgenden Pfade einheitlich an. Die Dateien `compose.fresh.yaml`, `web/Dockerfile`, `backend/Dockerfile` und `sql/schema.sql` müssen dort vorhanden sein. Kopiere **keine** `.env`, `web/konfiguration.php`, PDFs oder Datenbankdateien einer evtl. bereits laufenden Installation. Verwende keinen bereits benutzten Datenordner.

## 2. Vier Werte eintragen

Per SSH auf dem NAS:

```bash
cd /volume1/docker/eablage
cp .env.fresh.example .env.fresh
```

Bearbeite `.env.fresh`: `EABLAGE_WEB_PORT` muss frei sein (Beispiel `8084`), `EABLAGE_DATA_PATH` muss auf einen **neuen, leeren** Ordner zeigen (Beispiel `/volume1/eablage-data`). Ersetze die beiden Passwort-Platzhalter durch verschiedene starke Passwörter. Dieser eine Dateieintrag für `EABLAGE_DB_PASSWORD` wird an Web, Backend und MariaDB übergeben; es gibt keine zweite PHP-Konfiguration mit Passwort.

```bash
sudo chmod 600 .env.fresh
```

Bei UGOS-ACLs muss der Docker-Dienst die Projektdateien lesen können. Lege die Datenordner an (bei einem anderen Datenpfad den Befehl entsprechend anpassen):

```bash
sudo mkdir -p /volume1/eablage-data/{inbox,archiv,meta,export,backup}
sudo chown -R 33:33 /volume1/eablage-data
sudo chmod 755 /volume1/eablage-data /volume1/eablage-data/{inbox,archiv,meta,export,backup}
sudo chmod 755 /volume1/docker/eablage /volume1/docker/eablage/web
```

Die UID 33 ist der Webbenutzer und die voreingestellte Backend-Identität. Für einen anderen Datenpfad oder andere NAS-Rechte müssen die Schreibrechte entsprechend eingerichtet werden. Keine pauschalen `777`-Rechte setzen.

## 3. Starten

Alle folgenden Compose-Befehle im Projektverzeichnis ausführen. **Immer** dieselben drei Optionen verwenden; sie bestimmen die Konfiguration und den Projektnamen dieser Instanz. `eablage` ist der Beispiel-Projektname. Bei einer bestehenden Instanz immer ihren bisherigen Namen verwenden (beispielsweise `eablage-fresh`); ein anderer Name würde ein anderes Datenbank-Volume ansprechen:

```bash
cd /volume1/docker/eablage
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage config --quiet
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage up -d --build
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage ps
```

Beim ersten Start erstellt MariaDB die Datenbank, importiert `schema.sql`, `seed.sql` und anschließend die fünf internen Docker-Pfade. Das Datenbank-Volume gehört nur zum Compose-Projekt `eablage`. Die Weboberfläche sollte unter `http://NAS-IP:8084` erreichbar sein (Port gegebenenfalls an `.env.fresh` anpassen). Bei einer NAS-Firewall den Port für den gewünschten Client freigeben. Die Anwendung nicht ohne zusätzliche Zugangssicherung ins Internet stellen.

## 4. Erstinstallation prüfen

Die Tabellen und Pfade prüfen:

```bash
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage exec mariadb sh -c 'mariadb -u "$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SHOW TABLES; SELECT param_key, param_value FROM parameter;"'
```

Erwartet werden unter anderem `dokumente`, `parameter`, `/data/archiv/`, `/data/meta/` und `/data/export/`. Anschließend im Browser eine unkritische PDF hochladen, klassifizieren, archivieren und über die Suche wieder öffnen. Prüfe die PDF unter `archiv` und die JSON-Metadatei unter `meta`; teste danach Export und Sicherung. Falls weitere Instanzen laufen, zusätzlich kontrollieren, dass die PDF dort nicht erscheint.

Bei Problemen die Logs lesen:

```bash
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage logs mariadb --tail 100
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage logs eablage-web eablage-backend eablage-worker --tail 100
```

**Wichtig:** Die SQL-Initialisierung läuft nur bei einem erstmals angelegten, leeren Datenbank-Volume. Ein erneuter Start importiert nichts erneut. Ändere Passwörter nicht einfach in `.env.fresh`, nachdem MariaDB bereits initialisiert wurde; der angelegte Datenbankbenutzer behält sein bisheriges Passwort.

Zum Anhalten und Entfernen der Container die Konfiguration dieser Instanz verwenden:

```bash
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage down
```

Dieser Befehl behält Datenordner und Datenbank-Volume. `down -v` würde zusätzlich die Datenbank löschen; diese Option gehört nicht zum normalen Anhalten. Regelmäßige Updates und Sicherungen sind unabhängig vom Einsatzzweck erforderlich: [Aktualisierung](UPDATE.md), [Sicherung und Wiederherstellung](SICHERUNG.md).

## 5. Optional: Installation vollständig entfernen

Die folgenden Schritte sind nur für eine nicht mehr benötigte Instanz vorgesehen. Sie löschen deren Datenbank und Dokumente dauerhaft. Vorher eine zusammengehörige Sicherung erstellen und die Instanz, den Projektnamen sowie die Pfade prüfen. Im Projektverzeichnis zunächst die Konfiguration kontrollieren:

```bash
cd /volume1/docker/eablage
sed -n '/^EABLAGE_DATA_PATH=/p' .env.fresh
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage ps
```

Wenn der Datenpfad tatsächlich `/volume1/eablage-data` lautet und die
Container zur zu entfernenden Instanz gehören, das Compose-Projekt einschließlich seines
MariaDB-Volumes und der eigens gebauten Images entfernen:

```bash
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage down -v --rmi local
```

`-v` entfernt das Datenbank-Volume dieser Instanz. Anschließend die beiden
**zuvor geprüften** Verzeichnisse entfernen:

```bash
sudo rm -rf -- /volume1/eablage-data
sudo rm -rf -- /volume1/docker/eablage
```

Bei einem anderen Wert von `EABLAGE_DATA_PATH` den ersten Löschpfad nur nach
erneuter Prüfung anpassen. Das allgemein heruntergeladene Image `mariadb:11.4`
kann im Docker-Cache bleiben; es enthält keine Daten dieser Installation.

Für eine erneute Neuinstallation einen neuen, leeren Datenordner und ein leeres Datenbank-Volume verwenden. Eine bestehende Instanz wird stattdessen gemäß [Aktualisierung](UPDATE.md) aktualisiert.

