# Drittkomponenten und Lizenzen

Diese Übersicht beschreibt die wesentlichen Abhängigkeiten von eAblage 4.8.0. Die Python-Paketliste stammt aus dem aktuellen Lockfile. Maßgeblich für eine Inventur der Container sind die tatsächlich installierten Pakete und Lizenztexte. PDF.js wird lokal ausgeliefert. Ollama ist ein optionaler externer Dienst. Das Modell und seine Lizenz richten sich nach der jeweiligen Installation.

| Komponente | Verwendung | Lizenz / Hinweis |
| --- | --- | --- |
| FastAPI | Python-HTTP-API | MIT |
| Uvicorn | ASGI-Server | BSD-3-Clause |
| python-dotenv | Konfiguration | BSD-3-Clause |
| MySQL Connector/Python 26.10.0 | Datenbankzugriff im Backend | GPL-2.0 mit Oracle Universal FOSS Exception 1.0; siehe unten |
| PDF.js 6.5.438 | Lokaler PDF-Viewer im Browser | Apache License 2.0; zusätzliche Lizenztexte der mitgelieferten Ressourcen in `web/vendor/pdfjs/`; siehe [PDF-Vorschau](PDF_VORSCHAU.md) |
| PHP 8.5 | Weboberfläche | PHP License 3.01 |
| Apache HTTP Server | Webserver | Apache License 2.0 |
| Python 3.11 | Backend-Laufzeit | Python Software Foundation License |
| MariaDB | Datenbankserver, bei integrierter Datenbank als eigener Dienst | GPL-2.0 |
| Poppler (pdftotext) | PDF-Textextraktion als externes Programm | GPL; genaue Paketlizenz und Version im Image prüfen |
| Tesseract OCR | OCR als externes Programm | Apache License 2.0 |
| Tesseract Sprachdaten (deu/eng) | OCR-Sprachmodelle | Lizenz der konkreten Paketdateien separat prüfen |
| Debian und Systempakete | Basis der Container | Verschiedene Lizenzen; bei Imageweitergabe gesondert inventarisieren |

## Oracle MySQL Connector/Python

Der Connector bleibt unter seiner eigenen Lizenz. Die Oracle **Universal FOSS Exception 1.0** erlaubt unter ihren Bedingungen die Kombination mit anderer freier Software. Die Kombination mit dem unter AGPL-3.0-only stehenden eAblage-Quellcode wird auf dieser Grundlage vorgesehen; der Connector selbst wird nicht unter AGPL umgelizenziert.

Originaltext: https://oss.oracle.com/licenses/universal-foss-exception/

Bei einem Wechsel der Connector-Version, einer Veränderung des Connectors oder einer anderen Verteilungsform muss die Anwendbarkeit erneut geprüft werden.

## Python-Abhängigkeiten

Direkte Abhängigkeiten sind in `backend/requirements.in`, die konkret aufgelösten Pakete einschließlich transitiver Abhängigkeiten in `backend/requirements.txt` dokumentiert. Die Lizenz jedes transitiven Pakets ist vor einer vollständigen Distributionsfreigabe anhand der jeweiligen Paketmetadaten zu erfassen.

## Containerimages

Die Dockerfiles installieren zusätzlich Pakete des Basisbetriebssystems. Bei Verbreitung **fertig gebauter** Images müssen die zugehörigen Lizenz- und Quellcodepflichten eigenständig erfüllt werden. Eine Veröffentlichung des eAblage-Quellcodes und der Dockerfiles ist davon zu unterscheiden.

Diese Übersicht erhebt keinen Anspruch auf verbindliche Rechtsberatung.

## Vollständige Python-Paketliste für eAblage 4.8.0

Die folgende Liste ist aus `backend/requirements.txt` des geprüften Branches extrahiert und umfasst **sämtliche 16 festgeschriebenen Python-Pakete**, einschließlich transitiver Abhängigkeiten. Die Lizenzspalte nennt die übliche Projektlizenz, nicht eine unabhängig geprüfte Lizenzdatei jedes konkreten Wheels.

| Paket | Version | Übliche Lizenz |
| --- | --- | --- |
| annotated-doc | 0.0.5 | MIT |
| annotated-types | 0.8.0 | MIT |
| anyio | 4.15.1 | MIT |
| click | 8.5.0 | BSD-3-Clause |
| fastapi | 0.163.1 | MIT |
| h11 | 0.16.0 | MIT |
| idna | 3.19 | BSD-3-Clause |
| mysql-connector-python | 26.10.0 | GPL-2.0 mit Universal FOSS Exception |
| opentelemetry-api | 1.45.2 | [Apache-2.0](https://github.com/open-telemetry/opentelemetry-python/blob/main/opentelemetry-api/LICENSE) |
| pydantic | 2.13.5 | MIT |
| pydantic-core | 2.46.5 | MIT |
| python-dotenv | 1.2.4 | BSD-3-Clause |
| starlette | 1.6.0 | BSD-3-Clause |
| typing-extensions | 4.16.0 | PSF-2.0 |
| typing-inspection | 0.4.4 | MIT |
| uvicorn | 0.54.0 | BSD-3-Clause |

Vor der Bezeichnung als abschließend geprüfte Lizenzinventur müssen die Lizenzdateien und Paketmetadaten der tatsächlich installierten Distributionen überprüft werden.

## Containerinventur am tatsächlich gebauten Image

Die Dockerfiles installieren folgende zusätzliche Betriebssystempakete ausdrücklich:

- Web: `locales`, die PHP-Erweiterungen `mysqli` und `pdo_mysql` aus dem PHP-Quellpaket sowie Apache-Konfiguration.
- Backend und Worker: `poppler-utils`, `tesseract-ocr`, `tesseract-ocr-deu` und `tesseract-ocr-eng`.
- Beide Images enthalten darüber hinaus sämtliche Abhängigkeiten des jeweiligen Debian-Bookworm-Basisimages sowie von APT automatisch installierte Pakete.

Die tatsächlichen Paketlisten sind **nicht allein aus den Dockerfiles ableitbar**. Für die Freigabe auf dem System, auf dem die Images gebaut wurden, die Images neu bauen und danach für **jedes** Image die Paketliste, Paketversionen, Debian-Copyrightdateien und Python-Metadaten erfassen.

Beispiel für die Installation mit integrierter Datenbank:

```sh
docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage build
docker compose --env-file .env.fresh -f compose.fresh.yaml -p eablage images
```

Die ausgegebenen Image-IDs anschließend jeweils prüfen (Web, Backend und Worker; Backend und Worker können dasselbe Image verwenden):

```sh
docker run --rm --entrypoint sh IMAGE_ID -c 'dpkg-query -W -f="${binary:Package}\t${Version}\n" | sort'
docker run --rm --entrypoint sh IMAGE_ID -c 'find /usr/share/doc -name copyright -type f | sort'
docker run --rm --entrypoint sh IMAGE_ID -c 'python -m pip list --format=freeze 2>/dev/null || true'
```

Für eine rechtskonforme Weitergabe fertig gebauter Images genügt diese Liste allein nicht. Lizenztexte, Copyright-Hinweise und gegebenenfalls korrespondierende Quellen oder Bezugsangebote der enthaltenen Komponenten sind je nach Lizenz zu berücksichtigen. Besonders relevant sind GPL-Komponenten wie Poppler und MariaDB sowie die konkreten OCR-Sprachdaten.

