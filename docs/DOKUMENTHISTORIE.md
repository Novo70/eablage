# Ereignisprotokoll und Dokumenthistorie

Seit eAblage 4.3.0 werden Dokumentereignisse getrennt von den Dokumentmetadaten gespeichert.

## Umfang

Die Tabelle `ereignisprotokoll` ergänzt die bisherigen Dokumentdaten. Jeder Eintrag enthält ID, DokID, Zeitpunkt aus der Datenbankuhr, einen festen Ereignistyp, Quelle `Web` und JSON-Details. Indizes unterstützen die Auswahl nach Dokument bzw. Ereignis und Zeitpunkt. Im normalen Betrieb werden Einträge hinzugefügt. Bei endgültiger Dokumentlöschung mit `protokollLoeschdatenBehalten=nein` werden die Details sämtlicher Ereignisse dieser DokID geleert; die Ereignisse selbst bleiben erhalten. Es gibt keine separate Bearbeitungs- oder Löschfunktion für Ereignisse.

| Ereignistyp | Details |
| --- | --- |
| `archiviert` | Erkennungsmethode, E-/K-Regelreferenzen mit Version und Rang, ggf. Vergleichsdokument |
| `metadaten_geaendert` | Geänderte Felder mit vorherigem/neuem Wert; Kennzeichen für geänderten Dokumenttext; ggf. erneut verwendete Klassifikation |
| `papierkorb` | Verschiebung in den Papierkorb |
| `wiederhergestellt` | Wiederherstellung aus dem Papierkorb |
| `endgueltig_geloescht` | Bei aktivierter Aufbewahrung: Dokumentdatum, Partner, Betreff, Originaldateiname, ggf. Nummer sowie Kategorie-/Dokumenttyp-IDs und damalige Bezeichnungen; andernfalls leere Details |

Regelreferenzen dokumentieren die gemeldeten Treffer, nicht ausschließlich die Regeln, deren Vorschläge nach manueller Bearbeitung erhalten blieben. Die final gespeicherten Dokumentwerte stehen in `dokumente` und JSON. Kategorie-/Dokumenttypänderungen werden mit den stabilen IDs protokolliert. Erkennung ohne anschließende tatsächliche Speicherung ist eine Vorschau und kein dauerhaftes Ereignis.

Archivierung und Änderung verwenden für Dokument und Ereignis denselben DB-Zeitpunkt. Die Protokollfunktion führt keinen eigenen COMMIT durch. Scheitert ihr INSERT, wird die gesamte Dokumenttransaktion zurückgenommen. PDF/JSON und SQL sind weiterhin keine gemeinsame atomare Transaktion; die bestehenden Dateiprüfungen und Rücknahmen gelten weiter. Eine endgültige Löschung protokolliert die Entfernung des DB-Eintrags; fehlgeschlagene anschließende Dateibereinigung wird weiterhin als Fehler gemeldet.

Die Tabelle besitzt bewusst keinen löschenden Fremdschlüssel auf `dokumente`. Ereignisse bleiben nach endgültiger Dokumentlöschung erhalten. DokIDs werden nach dem bestehenden Verfahren nicht wiedervergeben. Es gibt zunächst keine automatische Aufbewahrungsfrist. Datenbankadministratoren können die Tabelle technisch verändern; die Funktion ist keine manipulationssichere Archivierung.

### Aufbewahrung nach endgültiger Löschung

`protokollLoeschdatenBehalten` ist standardmäßig **ja**. Bei **nein** ersetzt die endgültige Löschung alle JSON-Details der betroffenen DokID durch `{}`, einschließlich früherer Änderungen, Originalnamen und Klassifikationsreferenzen. Andere Dokumenthistorien bleiben unverändert. Das Löschereignis enthält ebenfalls `{}`. Ereignis-ID, DokID, Zeitpunkt, Ereignistyp und Quelle bleiben für die zeitliche Nachvollziehbarkeit erhalten. RegelID-Filter finden die bereinigten Ereignisse nicht mehr.

Bereinigung, Löschereignis und Dokumentlöschung erfolgen innerhalb derselben Transaktion. Die Einstellung wird dort erneut gelesen und bis zum Commit gesperrt. Ein Fehler bei Bereinigung oder Protokollierung nimmt die Dokumentlöschung und die Änderungen an alten Details zurück. Die Einstellung wirkt auf künftige endgültige Löschungen; ein Parameterwechsel bereinigt bereits gelöschte Dokumente nicht rückwirkend und rekonstruiert keine entfernten Details. Vorhandene Sicherungen behalten ihren damaligen Inhalt. [Einstellung und Einrichtung](KONFIGURATION.md#protokolldaten-bei-endgültiger-löschung).

Technische Fehler, reine Lesezugriffe, Metadateireparaturen und noch nicht archivierte Inbox-Aufträge gehören nicht zu dieser ersten Ausbaustufe. Technische Fehler bleiben in den bestehenden Anwendungslogs.

## Umstellung und Kompatibilität

1. Mit dem bisherigen Stand DB und Dateien sichern; alle Anwendungsdienste stoppen.
2. In der Ziel-Datenbank `sql/update_document_history.sql` ausführen, auch wenn die bisherige `dokument_protokoll` bereits angelegt wurde. Das Skript ergänzt außerdem den Aufbewahrungsparameter, ohne vorhandene Werte zu ändern. Die bisherige Tabelle wird mit allen Einträgen umbenannt; existiert noch keine Protokolltabelle, wird `ereignisprotokoll` angelegt. Der Vorgang ist wiederholbar. Existieren beide Tabellen, bricht die Umbenennung ab: Bestände prüfen und den Konflikt beheben, keine Tabelle ungeprüft löschen. Neuinstallationen erhalten die Tabelle über `schema.sql`.
3. Web, Backend und Worker gemeinsam aktualisieren; Formulare neu öffnen.
4. Die unten genannten Vorgänge testen und eine Sicherung mit Historie erstellen.

Die Migration verändert keine Dokumentdatensätze. Das alte Info-Feld bleibt unverändert lesbar, wird nicht mehr neu befüllt und nicht automatisch in scheinbar datierte Ereignisse zerlegt. Neue Dokumente erhalten dort NULL. Neue JSON-Metadateien verwenden Schema 3 ohne `info`. Bestehende Versionen 1/2 bleiben lesbar; die Archivprüfung ignoriert das alte Info-Feld beim fachlichen Vergleich. Es gibt keinen automatischen Massenumbau der Metadateien.

Die SQL-Sicherung enthält die vollständige Historie. Neue Dumps kennzeichnen dies durch `-- Ereignisprotokoll: 1` in der zweiten Kopfzeile; fehlt die sechste Tabelle, wird der Dump abgewiesen. Bisherige Historienbackups mit `-- Dokumentprotokoll: 1` und `dokument_protokoll` bleiben importierbar; bei der Rücksicherung werden ausschließlich die Tabellennamen in SQL-Anweisungen angepasst, die Ereignisdaten bleiben erhalten. Ältere unterstützte Dumps enthalten fünf Tabellen und keine Historie. Beim Rücksichern solcher Dumps wird die bisherige Protokolltabelle geleert. Rücksicherung bleibt an die leere Dokumenttabelle gebunden. Benötigte frühere Historie vorher sichern. Eine Rekonstruktion aus PDF und JSON kann Dokumentdaten, aber keine Ereignishistorie liefern.

## Anzeige und Auswertung

Menü **Ereignisprotokoll** oder Link in den technischen Informationen eines Dokuments öffnen. Der Link aus der Bearbeitung öffnet die Historie im selben Tab. **Zurück** führt zum Ausgangsdokument; Filter, Zurücksetzen und Blättern erhalten dieses Rücksprungziel. Ungespeicherte Formularwerte einschließlich neu eingelesenem Text werden mit JavaScript einmalig im tabbezogenen Sitzungsspeicher zwischengespeichert. Die ursprüngliche Dokumentrevision bleibt erhalten, damit zwischenzeitliche Änderungen beim späteren Speichern weiterhin erkannt werden. Ohne Rücksprungziel führt **Zurück** zur Dokumentübersicht. Je Seite werden 50 Ereignisse nach Zeitpunkt und ID absteigend angezeigt. DokID-, Ereignis- und RegelID-Filter lassen sich kombinieren; auch endgültig gelöschte Dokumente bleiben auffindbar. Die Ereignisse stehen in kompakten aufklappbaren Gruppen mit derselben Darstellung wie in den Einstellungen und kleineren Schriftgrößen aus der Ergebnisliste. Die Details sind standardmäßig eingeklappt; ein Klick auf die Ereigniszeile oder deren Aktivierung per Tastatur öffnet sie. Bisherige Archivierungsereignisse werden ebenfalls ohne redundante Erkennungszeichenfolge und Originaldateiname angezeigt; gespeicherte Altereignisse bleiben unverändert. Bei aktivierter Aufbewahrung erhalten neue Löschereignisse die identifizierenden Dokumentdaten vor der Löschung aus dem gesperrten Datenbankdatensatz. Bei deaktivierter Aufbewahrung werden neue und bisherige Details dieser DokID geleert. Bereits gelöschte Dokumente können rückwirkend nicht ergänzt werden.

Beispiel für eine Auswertung nach Ereignis und Tag:

```sql
SELECT DATE(Zeitpunkt) AS Tag, Ereignistyp, COUNT(*) AS Anzahl
FROM ereignisprotokoll
GROUP BY DATE(Zeitpunkt), Ereignistyp
ORDER BY Tag DESC, Ereignistyp;
```

Dokumente mit einer bestimmten gemeldeten Regel:

```sql
SELECT DISTINCT DokID
FROM ereignisprotokoll
WHERE JSON_SEARCH(Details, 'one', 'R0042', NULL,
                  '$.klassifizierung.regeln[*].regel_id') IS NOT NULL;
```

## Funktionsprüfung

- Neue PDF archivieren: genau ein Archivierungsereignis; neue DB-Info ist NULL; JSON-Schema 3 enthält kein `info`.
- Altbestandsdokument mit bestehendem Info-Text bearbeiten: Alttext unverändert, Feldänderungen korrekt protokolliert, JSON ohne `info`.
- Ohne Änderungen speichern bzw. Neu-Klassifikation abbrechen: kein neues Ereignis.
- Dokumenttext ändern: nur Änderungskennzeichen, keine Textkopie in Details.
- Papierkorb, Wiederherstellung und endgültige Löschung: jeweils ein Ereignis; Historie nach Löschung auffindbar.
- Beide Aufbewahrungswerte prüfen: Ja erhält alte und neue Details; Nein leert sämtliche Details ausschließlich für die gelöschte DokID. Auch den Rollback nach fehlgeschlagener Detailbereinigung bzw. Löschprotokollierung prüfen.
- In einer isolierten Testinstanz einen fehlgeschlagenen Protokoll-INSERT prüfen: Dokumentänderung darf nicht committed werden.
- Schema-1/2-Dateien mit altem Info-Feld prüfen: keine Abweichung nur wegen Info; fachliche Unterschiede weiterhin gemeldet.
- Neue Sicherung inklusive Ereignissen rücksichern; ältere Sicherung ohne Historie importieren und sicherstellen, dass keine fremden Protokollzeilen bleiben.

Automatisierte Prüfungen: Backend-Metadatentests, `tests/restore_preflight.php`, `tests/document_history.php` und `tests/metadata_php_integration.py`. PHP- bzw. Datenbankintegration erfordert die entsprechende lokale Laufzeit; NAS-Funktionstests bleiben vor Freigabe erforderlich.
