# eAblage mit integrierter Datenbank installieren

Diese Anleitung richtet eine neue eAblage-4.6.1-Instanz mit Weboberfläche, Backend, Worker und eigener MariaDB ein. Du brauchst Docker mit Compose, Zugriff auf eine Shell (auf dem NAS per SSH) und beim ersten Start Internetzugang für Images und Python-Pakete.

Für eine bestehende Instanz gilt die [Update-Anleitung](UPDATE.md). Falls du eine vorhandene MariaDB verwenden möchtest, folge der [Anleitung für eine separate Datenbank](INSTALL_STANDARD.md).

## 1. Projekt ablegen und konfigurieren

Entpacke den vollständigen Quellcode des gewünschten [Releases](https://github.com/Novo70/eablage/releases) in ein neues Projektverzeichnis, zum Beispiel `/volume1/docker/eablage`. Verwende die Compose-, Konfigurations- und SQL-Dateien aus demselben Release. Übernimm keine Konfigurationsdateien, PDFs oder Datenbankdateien einer vorhandenen Instanz.

```bash
cd /volume1/docker/eablage
(umask 077 && cp .env.fresh.example .env.fresh)
chmod 600 .env.fresh
```

Öffne `.env.fresh` im Texteditor und prüfe diese vier Werte.

| Einstellung | Wert |
| --- | --- |
| `EABLAGE_WEB_PORT` | Freier Webport, zum Beispiel `8084` |
| `EABLAGE_DATA_PATH` | Absoluter Pfad zu einem neuen, leeren Datenordner, zum Beispiel `/volume1/eablage-data` |
| `EABLAGE_DB_PASSWORD` | Starkes Passwort für den Datenbankbenutzer von eAblage |
| `MARIADB_ROOT_PASSWORD` | Anderes starkes Passwort für die MariaDB-Administration |

Verwende bei der Einrichtung keine Dollarzeichen, Rautezeichen, Leerzeichen oder Anführungszeichen in den Passwörtern. Compose übergibt das Anwendungspasswort an Weboberfläche, Backend, Worker und MariaDB.

## 2. Datenordner vorbereiten

Setze `data_dir` auf denselben Pfad wie `EABLAGE_DATA_PATH`. Die Befehle sind für einen neuen, leeren Ordner gedacht.

```bash
data_dir=/volume1/eablage-data
sudo mkdir -p -- "$data_dir"
for subdir in inbox archiv meta export backup; do
  sudo mkdir -p -- "$data_dir/$subdir"
  sudo chmod 755 -- "$data_dir/$subdir"
done
sudo chown -R 33:33 -- "$data_dir"
sudo chmod 755 -- "$data_dir"
```

Weboberfläche, Backend und Worker greifen mit UID/GID `33:33` auf die Dateien zu. Bei abweichenden NAS-Rechten müssen die Schreibrechte dazu passen. Prüfe bei Zugriffsfehlern die ACLs und Verzeichnisrechte von Projektordner und `web`. Für die Beispielinstallation kannst du die Verzeichnisrechte im Projektordner bei Bedarf mit `sudo chmod 755 . web` setzen. Vermeide pauschale `777`-Rechte.

## 3. Starten und testen

Führe alle Compose-Befehle im Projektverzeichnis aus. Verwende durchgehend dieselbe Env-Datei, Compose-Datei und denselben Projektnamen. Der Projektname bestimmt unter anderem, welches Datenbank-Volume Compose verwendet. Gib jeder weiteren Instanz einen eigenen Namen, Port, Datenordner und eine eigene Datenbank. Siehe [Mehrere Instanzen betreiben](KONFIGURATION.md#mehrere-instanzen-betreiben).

```bash
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage config --quiet &&
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage up -d --build &&
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage ps
```

Compose startet erst nach erfolgreicher Konfigurationsprüfung. Beim ersten Start richtet MariaDB das Datenbankschema, die Stammdaten und die fünf internen Pfade ein. Warte, bis MariaDB als `healthy` angezeigt wird und Weboberfläche, Backend und Worker laufen.

Öffne `http://NAS-IP:8084` mit der IP-Adresse deines NAS und dem eingestellten Port. Falls die NAS-Firewall den Zugriff blockiert, gib den Port für die vorgesehenen Clients frei. **eAblage hat keine eigene Anmeldung.** Beschränke den Zugriff auf vertrauenswürdige Clients. Für einen Zugriff aus dem Internet ist eine zusätzliche Zugangssicherung erforderlich.

1. Lade eine unkritische PDF hoch, klassifiziere und archiviere sie. Öffne sie anschließend über die Suche.
2. Prüfe die PDF in `archiv` und die JSON-Metadatei in `meta`. Bei mehreren Instanzen darf das Dokument nur in der vorgesehenen Instanz auftauchen.
3. Teste Export und Sicherung.

## Betrieb und Fehlersuche

Die SQL-Initialisierung läuft nur bei einem neuen, leeren MariaDB-Volume. Ein Neustart importiert die SQL-Dateien nicht erneut. Eine Änderung der Passwörter in `.env.fresh` allein ändert die bereits in MariaDB eingerichteten Konten nicht.

Zum Anhalten und Entfernen der Container dient `down`. Datenbank-Volume und Datenordner bleiben dabei erhalten.

```bash
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage down
```

Siehe auch [Aktualisierung](UPDATE.md) und [Sicherung und Wiederherstellung](SICHERUNG.md).

<details>
<summary>Logs, Datenbanktabellen und interne Pfade prüfen</summary>

```bash
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage logs --tail 100
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage exec mariadb sh -c 'mariadb -u "$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SHOW TABLES; SELECT param_key, param_value FROM parameter;"'
```

Erwartet werden unter anderem die Tabellen `dokumente` und `parameter` sowie die internen Pfade `/data/inbox/`, `/data/archiv/`, `/data/meta/`, `/data/export/` und `/data/backup/`.

</details>

<details>
<summary>Installation vollständig entfernen</summary>

**Die folgenden Schritte löschen die Datenbank und die Dateien dieser Instanz dauerhaft.** 
Erstelle zuvor eine zusammengehörige Sicherung. Prüfe im Projektverzeichnis den tatsächlichen Datenpfad und den Projektnamen der zu entfernenden Instanz.

```bash
pwd
sed -n '/^EABLAGE_DATA_PATH=/p' .env.fresh
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage ps -a
```

Wenn Projektname und Pfade zur gewünschten Instanz gehören, entferne das Compose-Projekt, sein Datenbank-Volume (`-v`) und die lokal gebauten Images (`--rmi local`).

```bash
sudo docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage down -v --rmi local
```

Entferne danach die beiden **zuvor geprüften** Verzeichnisse. Die folgenden Pfade sind Beispiele.

```bash
sudo rm -rf -- /volume1/eablage-data
sudo rm -rf -- /volume1/docker/eablage
```

Das heruntergeladene MariaDB-Image kann im Docker-Cache bleiben. Es enthält keine Daten der Instanz. Für eine erneute Neuinstallation sind ein leerer Datenordner und ein leeres Datenbank-Volume erforderlich.

</details>
