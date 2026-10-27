# Lokale KI mit Ollama

Die optionale KI ergänzt die Klassifizierung mit überprüfbaren Vorschlägen. Sie ist zunächst deaktiviert und kann manuell aufgerufen oder separat für die Hintergrundverarbeitung im Worker eingeschaltet werden. AutoKat und Textvergleich bleiben die erste Erkennungsstufe; die KI archiviert nicht automatisch.

## Einrichtung

1. In bestehenden Installationen nach einer Datenbanksicherung `sql/update_ollama.sql` in der Anwendungsdatenbank ausführen. Das Skript ergänzt drei Parameter und erhält bereits gespeicherte Werte. Bei Neuinstallationen sind sie in `seed.sql` enthalten. Nach Rücksicherung einer älteren Datenbank gegebenenfalls erneut ausführen.
2. Für Stammdaten-Erläuterungen zusätzlich `sql/update_master_explanations.sql`, für die bearbeitbare KI-Anweisung `sql/update_ollama_instruction.sql` ausführen. Letzteres erweitert `parameter.param_value` auf TEXT und ergänzt den neuen Parameter, ohne vorhandene Werte zu ersetzen.
   Für den optionalen Denkmodus zusätzlich `sql/update_ollama_thinking.sql` ausführen; vorhandene Werte bleiben erhalten.
3. Web, Backend und Worker aus demselben Entwicklungsstand bauen und bereitstellen, siehe [Update](UPDATE.md). Die Funktion gehört zu Version 4.4.0; für die eindeutige Standkennung den Tag oder Commit festhalten.
4. Unter **Einstellungen → Lokale KI** `ollamaAktiv` auf `ja` setzen, `ollamaURL` und `ollamaModell` eintragen und speichern.
   Für die Hintergrundverarbeitung zusätzlich `sql/update_ollama_background.sql` ausführen. Das Skript ergänzt einen reproduzierbaren KI-Cache und die standardmäßig ausgeschaltete Hintergrundoption.
5. **Verbindung testen** prüft die gespeicherten Werte vom Backendcontainer aus. Der PC muss eingeschaltet, Ollama gestartet und das Modell lokal installiert sein. Das Modell muss für den Test noch nicht in den Arbeitsspeicher geladen sein.

| Parameter | Bedeutung |
| --- | --- |
| `ollamaAktiv` | `ja` aktiviert die lokale KI; Vorgabe `nein` |
| `ollamaURL` | Beispielsweise `http://192.168.1.100:11434`; bei Installation zunächst leer |
| `ollamaModell` | Exakter Name aus `ollama list`; Vorgabe `qwen3.5:9b`, austauschbar |
| `ollamaDenkmodus` | `ja` aktiviert den Denkmodus mit 8192 Ausgabetokens; `nein` verwendet 1500 ohne Denkmodus; Vorgabe `nein` |
| `ollamaTextAnfang` | Zeichen vom Dokumentanfang; Vorgabe 9000, mindestens 1 |
| `ollamaTextEnde` | Zeichen vom Dokumentende; Vorgabe 3000, mindestens 0; 0 lässt den zusätzlichen Schlussteil weg |
| `ollamaHintergrund` | `aus`, `fehlende` (nur ohne automatische Klassifizierung) oder `alle`; Vorgabe `aus` |
| `ollamaAnweisung` | Fachliche Hinweise als mehrzeiliger Text bis 4000 Zeichen; leer oder fehlend verwendet Standardvorgaben |

Diese erste Variante verwendet HTTP mit einer privaten IPv4-Adresse (10/8, 172.16/12, 192.168/16) oder einer IPv6-ULA-Adresse. Hostnamen, öffentliche Adressen, Loopback, URL-Pfade und Zugangsdaten werden abgewiesen. Bei IPv6 die Adresse in eckige Klammern setzen. Die Übertragung im LAN ist unverschlüsselt.

Unter Windows Ollama vollständig beenden, die Benutzer-Umgebungsvariable `OLLAMA_HOST=0.0.0.0:11434` setzen und Ollama neu starten. Die Windows-Firewall für eingehendes TCP 11434 auf die NAS-IP und das private Profil begrenzen. Vorhandene ausdrückliche Ollama-Blockierregeln können diese Freigabe übersteuern. Eine Router-Portweiterleitung wird nicht benötigt. Ollamas lokale API besitzt keine eigene Anmeldung.

Vom NAS aus testen (PC-IP ersetzen):

```bash
curl --noproxy '*' --connect-timeout 3 --max-time 5 http://192.168.1.100:11434/api/tags
```

Der Verbindungstest in eablage ist zusätzlich erforderlich: Er prüft den tatsächlichen Weg aus dem Container und die Modellverfügbarkeit. Cloud-Kennungen und von Ollama ausgewiesene Remote-Modelle werden abgelehnt. Zusätzlich kann Ollama selbst mit `OLLAMA_NO_CLOUD=1` auf lokalen Betrieb begrenzt werden.

## Bedienung

1. Ein vorbereitetes Dokument in der Klassifizierung öffnen. Bei vorhandenem Text und aktivierter Funktion steht im Drei-Punkte-Menü **KI-Vorschlag erstellen** zur Verfügung.
2. Im Drei-Punkte-Menü **KI-Vorschlag erstellen** anklicken. Eine kurze Statuszeile zeigt zunächst den Bearbeitungsstatus. Besonders hilfreich ist dies, wenn AutoKat und Textvergleich keine brauchbare Zuordnung liefern.
3. Die Vorschläge betreffen Datum, Partner, Betreff, Kategorie, Dokumententyp, Nummer und Stichworte/Tags. Leere Textfelder (auch nur Leerzeichen) und die Auswahl „Keine Zuordnung“ werden direkt ergänzt und mit einem violetten **KI**-Badge gekennzeichnet. Die Angaben bleiben bearbeitbare Vorschläge bis zum Speichern. Bereits befüllte Felder behalten ihren Wert und zeigen abweichende Vorschläge direkt darunter. Mit den aktuellen Feldwerten und der PDF vergleichen. Für Datum und Nummer kann die belegende Textstelle aufgeklappt werden. Kategorie 2 wird bei den ergänzenden Tags berücksichtigt, erhält aber keinen eigenen KI-Vorschlag.
4. Jeden gewünschten Wert über die anklickbare Anzeige **KI: … → Übernehmen** übernehmen. Identische Werte erhalten nur den Hinweis **KI bestätigt**; dies ändert keine Angaben oder Herkunftskennzeichnungen. Nach der Übernahme wird die Vorschlagsanzeige ausgeblendet und am Feld erscheint das violette Herkunfts-Badge **KI**. Wird der Wert anschließend geändert oder geleert, erscheint der ursprüngliche KI-Vorschlag wieder zum Übernehmen, ohne erneute KI-Anfrage. Dies gilt für Datum, Partner, Betreff, Kategorie, Dokumententyp, Nummer und Tags, sowohl nach automatischer Ergänzung als auch nach bewusster Übernahme; Textbelege für Datum und Nummer bleiben aufklappbar. Ein Klick ersetzt auch einen abweichenden, bereits gefüllten Wert direkt, ohne Bestätigungsdialog. Alle Angaben bleiben bis zum Speichern manuell änderbar; **Abbrechen** verwirft die ungespeicherte Klassifizierung. Direkt ergänzte Angaben können vor dem Speichern frei korrigiert werden. Während der Anfrage eingegebene Werte werden nicht automatisch überschrieben.
5. Das Dokument wie gewohnt prüfen und speichern. Erst dann wird es archiviert. Das Archivierungsprotokoll kennzeichnet übernommene KI-Unterstützung einschließlich Modellname.

Manuell abgerufene KI-Vorschläge werden nicht als separater dauerhafter Entwurf gespeichert. Hintergrundvorschläge werden dagegen als reproduzierbarer Cache am Eingangsauftrag gespeichert; sie werden nicht archiviert und sind nicht Teil der Datenbanksicherung. Übernommene Formularwerte bleiben bei den bestehenden Rücksprüngen über Regel-/Dokumentlinks erhalten. Ein gewöhnliches Neuladen verwirft ungespeicherte Eingaben wie bisher. Bei ausgeschaltetem PC, Timeout oder fehlerhafter Antwort bleiben die aktuellen Eingaben erhalten und die manuelle Archivierung ist möglich.

Die Statuszeile zeigt laufende Verarbeitung, Fehler oder das Fehlen verwertbarer Angaben. Nach erfolgreicher Erkennung mit Vorschlägen verschwindet sie; Vorschläge und KI-Herkunftskennzeichnungen bleiben direkt an den Feldern. Modellinformationen, Verlauf und weitere Hinweise stehen in den Erkennungsdetails. Ein erneuter Aufruf über das Menü erstellt einen neuen Vorschlag.

Tags enthalten höchstens fünf konkrete, durch Komma getrennte Begriffe mit zusätzlichem Informationswert. Die KI berücksichtigt aktuelle Formularangaben, beide Kategorien und ihre übrigen Vorschläge. Wiederholungen, allgemeine Begriffe und Stichworte, deren Wörter bereits vollständig in den übrigen Angaben enthalten sind, werden herausgefiltert. Die Oberfläche prüft dies nochmals gegen die aktuellen Werte, bevor sie Tags anzeigt. Sinngleiche Begriffe und die fachliche Richtigkeit bleiben zu prüfen. Ohne ergänzende Inhalte darf der Tags-Vorschlag leer bleiben.


## KI im Hintergrund

Unter **Einstellungen → Lokale KI → KI im Hintergrund** (`ollamaHintergrund`) stehen drei Betriebsarten zur Verfügung:

| Auswahl | Verhalten |
| --- | --- |
| Aus | Nur manuell aufgerufene KI; Vorgabe für bestehende und neue Installationen |
| Nur ohne automatische Klassifizierung | KI, wenn weder eine AutoKat-Klassifikationsregel getroffen hat noch ein erfolgreicher Textvergleich eine Kategorie liefert |
| Für alle Dokumente | Ergänzender KI-Vorschlag für jeden vorbereiteten Eingang mit Dokumenttext |

Treffer von Ergänzungsregeln allein verhindern die KI nicht, auch wenn dadurch bereits eine Kategorie eingetragen ist. Fehlende Datum-/Nummerangaben allein lösen dagegen keine KI aus, wenn eine automatische Klassifizierung vorliegt. Vorhandene Angaben dienen als vorläufiger Kontext; sie werden nicht ungeprüft als richtige Zuordnung behandelt. Die Betriebsart beurteilt vorhandene Treffer, nicht deren fachliche Richtigkeit.

Die Hintergrundoption benötigt `ollamaAktiv=ja`, einen erreichbaren lokalen Ollama-PC und den laufenden Inbox-Worker (`EABLAGE_INBOX_ENABLED`). Nach Sicherung und Schreibpause `sql/update_ollama_background.sql` ausführen und Web, Backend und Worker aus demselben Commit bereitstellen. Neuinstallationen enthalten Tabelle und Parameter in Schema/Seed. Betriebsart speichern; sie gilt für die nächsten Workerprüfungen, auch für bereits vorbereitete Eingänge. Nach Änderungen die Klassifizierungsseite neu öffnen; ungespeicherte Eingaben vorher sichern.

OCR und AutoKat/Textvergleich werden zuerst vorbereitet. Ein eigener Thread im bestehenden Worker bearbeitet die KI-Warteschlange nacheinander, während die reguläre Eingangsverarbeitung weiterläuft. Manuelle und Hintergrund-KI teilen eine Datenbanksperre: Pro Anwendungsdatenbank läuft höchstens eine Generierung. Ein manueller Aufruf während einer laufenden KI-Anfrage meldet den belegten Dienst und kann danach wiederholt werden. Mehrere voneinander unabhängige Installationen auf demselben Ollama-PC teilen diese Sperre nicht.

Beim Öffnen werden vorhandene Hintergrundvorschläge ohne neue Modellanfrage abgerufen. Noch ausstehende Vorschläge werden automatisch nachgeladen. Die Maske bleibt bedienbar. Leere, seit dem Öffnen unveränderte Felder werden ergänzt und mit KI markiert; vorhandene oder inzwischen bearbeitete Angaben bleiben erhalten. Auch ein zwischenzeitlich bearbeitetes und wieder geleertes Feld wird nicht automatisch befüllt. Bei Rückkehr mit einem wiederhergestellten Formularentwurf werden Hintergrundwerte nur als anklickbare Vorschläge angezeigt, damit auch bewusst leere Eingaben erhalten bleiben. Abweichende Vorschläge sind wie bisher per Klick übernehmbar. Schließen der Vorschlagsanzeige, Speichern oder ein manueller KI-Abruf verhindert die spätere Übernahme dieser Hintergrundantwort. Der Menüaufruf erstellt weiterhin einen neuen Vorschlag mit den aktuellen Formularwerten.

Datei-/Textwechsel, erneute OCR, Archivierung und Änderungen der KI-Konfiguration oder Stammdaten während der Generierung verhindern die Veröffentlichung des alten Ergebnisses. Bei Änderungen nach einer abgeschlossenen Generierung erkennt die Oberfläche den veralteten Kontext beim Abruf und lässt den Worker einen neuen Vorschlag vorbereiten. Archivierte/verworfen/entfernte Aufträge erhalten keine Vorschläge. Der Cache wird bei einer späteren aktiven Workerprüfung bereinigt und bei Datenbankrücksicherung geleert.

Bei Fehlern bleiben normale Erkennung und manuelle Klassifizierung verfügbar. Bei vorübergehenden Verbindungsfehlern pausiert der KI-Thread fünf Minuten vor dem nächsten Versuch; solche Aufträge werden gegenüber neuen Aufträgen nachrangig bearbeitet. Endgültig gescheiterte Generierungen lösen bei unverändertem Dokument und Erkennungskontext keinen weiteren automatischen Versuch und keine globale Fehlerpause aus. Ein manueller Neuversuch bleibt möglich. Änderungen an Modell, Denkmodus, Anweisung, Stammdaten oder Textauswahl ermöglichen einen neuen Worker-Versuch; bei geöffneter Klassifizierung die Seite danach neu öffnen. Nach einem Workerabbruch wird ein unvollständiger Auftrag erneut übernommen, sobald die gemeinsame Sperre frei ist. Die unten beschriebenen Grenzen für Erstversuch, Nachlauf und Rückfall gelten auch im Hintergrund.

Der KI-Cache enthält Modell, validierte Vorschläge, Hinweise und eine begrenzte Durchlaufhistorie, keine vollständige Anfrage oder separaten Dokumenttext. Auch bei Fehlern bleiben die Durchlaufmetadaten im vorhandenen Feld `ergebnis` erhalten. Die Wartungsanzeige **KI-Anfrage anzeigen** öffnet die Erkennungsdetails und zeigt dort die letzte manuelle Anfrage der geöffneten Seite; der Hintergrundabruf stellt keine gespeicherte Request-Diagnose bereit.


## Hintergrundstatus erkennen

Die Eingangsliste behält den regulären Status **Zur Prüfung bereit** bei: Das Dokument kann unabhängig von der KI manuell geprüft werden. Darunter erscheint bei eingeschalteter und für das Dokument erforderlicher Hintergrund-KI ein eigener Status:

- **KI wartet:** Der Auftrag ist für die KI-Verarbeitung vorgemerkt.
- **KI läuft:** Der Worker bearbeitet diesen Auftrag.
- **KI-Verarbeitung pausiert:** Die Verarbeitung wartet auf den nächsten zulässigen Versuch; eine globale Workerpause wird zusätzlich zentral angezeigt.
- **KI-Vorschlag verfügbar:** Gespeicherter Vorschlag liegt vor; beim Öffnen wird seine Aktualität nochmals geprüft.
- **KI fehlgeschlagen:** Der KI-Auftrag ist gescheitert. Fehlergrund, letzter Versuch und Durchlaufhistorie stehen in den Erkennungsdetails der Klassifizierung. Eine Fehlerpause macht die übrigen wartenden Dokumente nicht zu Fehlerfällen.

In der Klassifizierung bleiben der kurze KI-Status, die feldweisen Vorschläge und ihre Übernahme am Formular sichtbar. Warteschlangenposition, Laufzeit-/Pauseninformationen, Workerhinweise, Durchlaufhistorie, Textkürzung bei langen Dokumenten und weitere KI-Hinweise stehen gesammelt unter **Erkennungsdetails**. Ein fehlgeschlagener manueller KI-Aufruf meldet am Formular nur den Fehlschlag mit einem anklickbaren Verweis auf die Erkennungsdetails; dort ist der vollständige Fehler verfügbar. Aktuell wartende/fehlgeschlagene Vorschläge werden alle fünf Sekunden abgefragt; alle Regeln zum Schutz bearbeiteter Felder gelten unverändert.

Einmal über der Eingangsliste erscheint **Keine aktuelle Worker-Rückmeldung**, wenn keine Rückmeldung vorliegt, der Worker ausdrücklich beendet wurde oder die letzte Rückmeldung älter als 45 Sekunden ist. Der Worker schreibt sie in einem eigenen Thread ungefähr alle zehn Sekunden und zusätzlich bei Statuswechseln, auch während langer KI-Anfragen und Fehlerpausen. Eine fehlende Rückmeldung beweist nicht, dass der Prozess beendet ist; auch ein Datenbank-/Verbindungsproblem kann die Ursache sein. Die Maske meldet fehlende Rückmeldung ebenfalls. Fehler beim Statusabruf werden als **Status derzeit nicht abfragbar** bzw. **KI-Status nicht verfügbar** kenntlich gemacht. Die normale Klassifizierung bleibt möglich.

Für bestehende Installationen nach Sicherung und Schreibpause `sql/update_ollama_worker_status.sql` ausführen; Web, Backend und Worker aus demselben Commit aktualisieren. Die wiederholbare Migration ergänzt ausschließlich die Laufzeittabelle `ki_worker_status`; bestehende Einstellungen und Vorschläge bleiben erhalten. Neue Installationen erhalten sie aus `schema.sql`. Die Tabelle enthält nur Rückmeldung, aktuellen Auftrag, Laufzeit-/Pausenzeitpunkte und einen allgemeinen Fehlerhinweis, keinen Dokumenttext. Sie wird nicht gesichert und bei Rücksicherung geleert. Nach Rücksicherung den Worker neu starten, damit er seine Rückmeldung neu anlegt. Pro Anwendungsdatenbank ist ein Worker vorgesehen.


## Textauswahl einstellen

Unter **Einstellungen → Lokale KI** bestimmen `ollamaTextAnfang` und `ollamaTextEnde`, wie viele Zeichen vom Anfang und Ende verwendet werden. Vorgaben und Fallback bei fehlenden Parametern sind 9000/3000. Anfang ist mindestens 1, Ende mindestens 0; die Summe darf die feste technische Grenze von 12000 Zeichen nicht überschreiten. Die Oberfläche prüft die Kombination auch bei Änderung nur eines Wertes. Backend und Worker prüfen gespeicherte Werte ebenfalls.

Ist ein Dokument höchstens so lang wie die Summe, wird es vollständig und ohne doppelte Abschnitte ausgewertet. Bei längeren Dokumenten werden die beiden getrennten Ausschnitte mit einem Kürzungshinweis verbunden. Mit **9000/0** werden bei langen Dokumenten nur die ersten 9000 Zeichen verwendet; kürzere Dokumente bleiben vollständig. Die technische Kontextgrenze für Anweisung, Stammdaten und Text gilt zusätzlich unverändert. Gezählt werden Textzeichen, keine Tokens oder Dateibytes.

Änderungen speichern und einen neuen Vorschlag abrufen. Ein weiterer Containerneubau ist nach der erstmaligen Einrichtung nicht erforderlich. Während der Generierung geänderte Werte machen die alte Antwort ungültig. Hintergrundvorschläge tragen die Kennzeichnung ihrer Textauswahl; geänderte Werte machen sie für neue Abrufe ungültig und planen sie bei der nächsten Workerprüfung neu ein, auch ohne vorheriges Öffnen des Dokuments. Bereits übernommene Formularwerte bleiben erhalten.

Die Diagnose zeigt unter **Textauswahl (Zeichen)** Anfang, Ende, Gesamtgrenze, Länge des ursprünglichen Texts, ausgewählte Zeichen und Länge des gesendeten Dokumenttexts einschließlich Kürzungsmarker. **Dokumenttext wie gesendet** zeigt die konkrete Auswahl. Der Warnhinweis verwendet die tatsächlich eingestellten Werte. Weniger Eingabetext garantiert weder kürzere Generierung noch gleichbleibende fachliche Qualität; beides mit denselben Dokumenten prüfen.

Für bestehende Installationen nach Sicherung und Schreibpause `sql/update_ollama_text_selection.sql` ausführen und Web, Backend und Worker aus demselben Commit bereitstellen. Das wiederholbare Skript ergänzt beide Parameter ohne Überschreiben bestehender Werte sowie `ki_vorschlaege.textfenster`. Fehlt die Cachetabelle, wird sie angelegt, ohne die Hintergrund-KI zu aktivieren. Bisherige Vorschläge werden mit ihrer bisherigen Auswahl 9000/3000 gekennzeichnet; Vorschläge und Versuchzeitpunkte bleiben erhalten. Neuinstallationen enthalten Spalte und Parameter in Schema/Seed.

## Umfang und Grenzen

- Übertragen werden der vorbereitete Dokumenttext, die Namen und optionalen Erläuterungen der aktiven Kategorien und Dokumententypen, die aktuellen Formularangaben sowie die Klassifizierungsanweisung. Weder Archivbestand noch PDF-Bilder werden übertragen.
- Die Textauswahl ist über `ollamaTextAnfang` und `ollamaTextEnde` einstellbar; Vorgabe erste 9.000 und letzte 3.000 Zeichen, insgesamt höchstens 12.000. Kürzere Dokumente werden vollständig ausgewertet; die Oberfläche weist bei Kürzung auf die tatsächliche Auswahl hin. Zu umfangreiche Stammdaten führen zu einer verständlichen Ablehnung.
- Kurzer Verbindungstest; je Generierungsversuch 120 Sekunden einschließlich Modellladen, beim ersten Denkversuch zusätzlich höchstens 30 Sekunden Nachlauf zur Bestätigung des Endes. Ein Rückfall startet erst nach bestätigtem Ende. Ein Browserabbruch kann eine bereits laufende Berechnung auf dem PC nicht zuverlässig beenden.
- Kategorien und Dokumententypen werden ohne IDs übermittelt: als Namensliste oder, bei vorhandenen Erläuterungen, als Zuordnung von Name zu Erläuterung. Auch aktuelle Formularzuordnungen werden vor dem Versand in Namen übersetzt. Das Antwortschema erlaubt nur diese Namen oder einen leeren Wert. Python ordnet bekannte Namen exakt den IDs zu; abweichende Schreibweisen und unbekannte Namen werden ohne Schätzung verworfen. Doppelte oder ungültige aktive Namen verhindern die Anfrage. Das Schema wird nur über Ollamas `format` übergeben.
- Die Modellantwort muss dem vorgegebenen JSON-Format entsprechen. Unbekannte Stammdaten, unmögliche Datumswerte und unbelegte Nummern/Datumsangaben werden verworfen. Eine vorhandene Textstelle beweist noch nicht die fachliche Richtigkeit des vorgeschlagenen Datums.
- Der PDF-Hash, der angezeigte Text und der Auftragsstatus werden geprüft. Während einer Anfrage geänderte oder archivierte Entwürfe werden abgewiesen.
- Fehlerhafte OCR bleibt eine mögliche Fehlerquelle. Die KI kann auch formal gültige, aber fachlich falsche Angaben liefern. Sie archiviert daher niemals automatisch.

Das Modell muss strukturierte JSON-Ausgaben unterstützen; die Generierung verwendet Temperatur 0 und 16.384 Kontexttokens. Unter **Einstellungen → Lokale KI → ollamaDenkmodus** kann der Denkmodus aktiviert werden: `ja` sendet `think: true` und ein gemeinsames Budget von 8192 Tokens für Denken und Antwort, `nein` sendet `think: false` und 1500 Tokens. Ein fehlender Parameter entspricht `nein`. Das Modell muss den gewählten Denkmodus unterstützen; `qwen3.5:9b` wurde damit erfolgreich getestet. Der Denkmodus kann fachliche Zuordnungen verbessern und länger dauern; je Versuch gelten 120 Sekunden zuzüglich des begrenzten Nachlaufs beim ersten Denkversuch (siehe Rückfallverhalten). Änderungen zuerst speichern, dann einen neuen Vorschlag abrufen. Änderungen während der Generierung machen das Ergebnis ungültig. Die tatsächlich verwendeten Werte sind unter **KI-Anfrage anzeigen** sichtbar. Ein erfolgreicher Einzeltest garantiert keine korrekte Zuordnung weiterer Dokumente. Größere Modelle können auf schwacher Hardware das Zeitlimit überschreiten. Der praktische Nutzen und die Modellwahl sind mit repräsentativen eigenen Dokumenten zu beurteilen.

## Einmaliger Rückfall ohne Denkmodus

Bei aktiviertem Denkmodus darf eAblage nach einem Abbruch einmal mit `think: false` und `num_predict: 1500` erneut anfragen. Prompt, vorhandene Formularwerte, Schema, Modell und Sampling bleiben gegenüber dem Erstversuch unverändert. Es werden weder unvollständige Antworten noch Gedankentexte in den zweiten Prompt übernommen. Die gemeinsame Generierungssperre bleibt über beide Durchläufe bestehen.

- Meldet Ollama `done: true` und `done_reason: length`, beginnt der Rückfall unmittelbar nach erneuter Prüfung des Dokument- und Konfigurationsstands.
- Nach 120 Sekunden ist die Nutzzeit des Denkversuchs abgelaufen. Die bestehende Verbindung bleibt höchstens weitere 30 Sekunden offen, um das Ende zu bestätigen. Kommt innerhalb dieses Nachlaufs `done: true`, wird die verspätete erste Antwort verworfen und der Rückfall gestartet. Ohne bestätigtes Ende wird keine zweite Generierung gesendet. Ein HTTP-Timeout oder das Schließen einer Verbindung beweist nicht, dass Ollama die Berechnung bereits beendet hat. Es gibt kein globales Entladen oder Beenden des Modells.
- Ist der Denkmodus ausgeschaltet, bleibt es bei einem Versuch. Eine gültige Antwort mit leeren Feldern löst keinen Rückfall aus. Nichterreichbarkeit, Modellfehler oder eine ungültige JSON-Antwort lösen ebenfalls keinen Rückfall aus.
- Scheitert auch der Versuch ohne Denkmodus, endet die Verarbeitung. Die Eingaben bleiben erhalten. Der Worker speichert für endgültige Fehler lediglich die Kennzeichnung `retryable: false` im vorhandenen Cachefeld `ergebnis`, den bisherigen Kontextfingerabdruck und die Fehlermeldung. Er wiederholt dieselbe Generierung bei unverändertem Kontext nicht. Vorübergehende Verbindungsfehler vor einem Rückfall bleiben nach bisheriger Regel wiederholbar.

Erfolgreiche Rückfallvorschläge tragen den Hinweis, dass sie ohne Denkmodus erstellt wurden und besonders sorgfältig zu prüfen sind. Dieser Hinweis bleibt auch bei Hintergrundvorschlägen erhalten. In der manuellen Diagnose stehen unter **KI-Durchläufe** die Denkmoduswerte, Abschlussgründe und verfügbaren Messwerte je Versuch. Die angezeigte und kopierbare Anfrage sowie die Hauptmesswerte gehören zum letzten tatsächlich gesendeten Versuch. Antwort- und Gedankentexte werden nicht in die Diagnose aufgenommen. Die nachfolgend beschriebene Hintergrundhistorie enthält nur freigegebene Metadaten.

Für die gemeinsame Wartezeit sind Browser (320 Sekunden), PHP-Backendtransport (310 Sekunden), PHP-Ausführung (330 Sekunden) und Apache (340 Sekunden) angepasst. Ein vorgeschalteter Reverseproxy muss entsprechend lange Anfragen zulassen. Das gesamte Versuchspaar benötigt für die Generierung höchstens 270 Sekunden zuzüglich kurzer Kontext-/Verbindungsprüfungen. Ein Browserabbruch beendet eine laufende Berechnung nicht zuverlässig.

Web, Backend und Worker gemeinsam aus diesem Stand neu bauen und bereitstellen; wegen der Apache-Konfiguration auch das Webimage neu bauen. Es ist kein SQL-Update erforderlich. Fachliche KI-Anweisungen und Stammdaten werden nicht geändert. Ein Erfolg des Rückfalls garantiert keine fachlich richtige Klassifizierung; reale problematische Dokumente im Vergleich prüfen.

## Hintergrundverlauf erkennen

Die **Eingangsliste** zeigt ausschließlich den kurzen KI-Bearbeitungsstatus; Verlauf und aufklappbare KI-Fehlerdetails entfallen dort. In der **Klassifizierung** stehen Verlauf, ergänzende KI-Hinweise und ausführliche Fehler ausschließlich unter **Erkennungsdetails → KI-Erkennung**. Die Angaben bleiben im KI-Cache gespeichert und sind auch nach erneutem Öffnen verfügbar. Es handelt sich um den Verlauf des letzten Hintergrundauftrags, nicht um ein vollständiges dauerhaftes Ereignisprotokoll aller manuellen und automatischen Anfragen. Bei einer neuen Hintergrundverarbeitung ersetzt der neue Verlauf den bisherigen.

Die Zusammenfassung unterscheidet insbesondere:

- **Fallback nicht gestartet: Ende des Erstversuchs nicht bestätigt.** Der erste Durchlauf hat keine Endbestätigung geliefert; es wurde keine zweite Generierung gesendet.
- **Fallback ohne Denkmodus gestartet, aber fehlgeschlagen.** Zwei Durchläufe haben stattgefunden. Die Fehlermeldung nennt den Fallback ausdrücklich, auch bei einem harten Timeout.
- **Fallback ohne Denkmodus erfolgreich.** Der zweite Durchlauf hat einen validierten Vorschlag geliefert.
- **Denkmodus deaktiviert; kein Fallback vorgesehen.** Ein einzelner Durchlauf ohne Denkmodus wurde angefordert.
- **Kein KI-Durchlauf gestartet.** Die Verarbeitung scheiterte bereits vor dem Generierungsaufruf.

Im Abschnitt **Erkennungsdetails → KI-Erkennung → KI-Verlauf** stehen bis zu zwei Zeilen mit Erstversuch/Fallback, Denkmodus, Ergebnis beziehungsweise Abbruchgrund, bestätigtem oder unbestätigtem Ende und der lokal gemessenen Laufzeit. Falls verfügbar, wird die Anzahl erzeugter Tokens angegeben. Die lokale Laufzeit ist die gemessene Wartezeit des Backendaufrufs; Ollamas numerische Messwerte können zusätzlich in den Metadaten vorliegen. Bei einem Timeout ohne Antwort wird keine Ollama-Laufzeit erfunden.

Für alte Cacheeinträge ohne gespeicherte Versuche erscheint **Keine Durchlaufhistorie vorhanden; ältere Versuche lassen sich nicht nachträglich rekonstruieren.** Ein vorhandener Fehlertext allein ist kein Beweis für einen erfolgten oder unterbliebenen Fallback. Die Aktualisierung löst keine automatische Wiederholung solcher alten endgültigen Fehler aus.

Gespeichert werden im bestehenden JSON-Feld `ki_vorschlaege.ergebnis` höchstens zwei freigegebene Versuchsdatensätze unter `attempts`: Denkmodus, fester Ergebnisstatus, Endbestätigung, lokale Laufzeit, bekannter Abschlussgrund und freigegebene numerische Ollama-Messwerte. Freie Fehlertexte, Anfragen, Dokumenttexte, Modellantworten und Denkprotokolle werden nicht in diese Historie übernommen. Die bisherige allgemeine Fehlermeldung bleibt im Feld `fehler`. Schema und Parameter ändern sich nicht; kein SQL-Update erforderlich. Backend, Worker und Web gemeinsam aktualisieren.

## Technische Prüfung

`backend/tests/test_ollama.py` prüft HTTP-Transport mit einem lokalen Testserver, Modell-/Antwortvalidierung, veraltete Ergebnisse und die API. `tests/ollama_browser.test.cjs` prüft direkte Befüllung leerer Felder, Erhalt zwischenzeitlicher Eingaben, Übernahme ohne Bestätigungsdialog, Tags, Textbelege, Badges, sichere Textdarstellung und Fehlererhalt in Chromium. `tests/ollama_php_integration.py` prüft CSRF, Datenbankbindung und die PHP-Brücke. `tests/document_history.php` prüft die Kennzeichnung im Protokoll.

```bash
PYTHONPATH=backend python -m unittest discover -s backend/tests
node --test tests/ollama_browser.test.cjs
python tests/ollama_php_integration.py
php tests/document_history.php
```

Für den Browsertest werden Playwright und dessen Chromium benötigt. Zusätzlich in der eigenen Testinstallation prüfen: Verbindung bei laufendem/ausgeschaltetem PC, falscher Modellname, erfolgreicher Vorschlag, manuelle Korrektur, Archivierung und Dokumenthistorie. Die Modellqualität kann nicht mit simulierten Antworten bewertet werden.

Offizielle Referenzen: [Ollama-Netzwerkkonfiguration](https://docs.ollama.com/faq), [Chat-API](https://docs.ollama.com/api/chat), [strukturierte Ausgabe](https://docs.ollama.com/capabilities/structured-outputs).

Die Umstellung auf Stammdatennamen benötigt kein SQL-Update. Web, Backend und Worker gemeinsam neu bauen und starten. Beim Abruf werden Hintergrundvorschläge aus der bisherigen ID-Anfrage als veraltet erkannt und anschließend neu vorbereitet. Ein Qualitäts- oder Laufzeitvorteil ist noch nicht nachgewiesen; Denkmodus, Tokenbudget und Abtastparameter bleiben unverändert.

## Erläuterungen der Ablagesystematik

Die [Stammdatenverwaltung](STAMMDATEN.md#optionale-erläuterung) bietet für jede Kategorie und jeden Dokumententyp eine optionale Erläuterung. Die KI erhält Namen und vorhandene Erläuterungen gemeinsam, um fachliche Zuordnungen und Abgrenzungen zu berücksichtigen. Leere Erläuterungen sind zulässig. Die KI schlägt weiterhin keine Kategorie2 vor; feste Kombinationen gehören in AutoKat. Erläuterungen verbessern den Kontext, garantieren aber keine bestimmte Zuordnung. Umfangreiche Erläuterungen zählen zur bestehenden Begrenzung des KI-Kontexts. Änderungen der aktiven Stammdaten während einer Anfrage machen deren Ergebnis ungültig.

## Fachliche KI-Anweisung bearbeiten

Unter **Einstellungen → Lokale KI → ollamaAnweisung** lässt sich der fachliche Teil der KI-Anweisung als mehrzeiliger Text bearbeiten. Das SQL-Update und Neuinstallationen hinterlegen die Standardvorgaben. Diese gewichten ausdrückliche Ausschlüsse in den Stammdaten-Erläuterungen höher als thematische Ähnlichkeit und verlangen eine unabhängige Prüfung bereits ausgefüllter Formularwerte. Weitere Vorgaben betreffen Partner, Betreff, Datum, Nummer und ergänzende Tags.

Ein leerer oder fehlender Parameter verwendet dieselben Standardvorgaben aus dem Backend. Änderungen zuerst speichern und anschließend **KI-Vorschlag erstellen** erneut aufrufen. Die gespeicherte Anweisung wird bei jeder neuen Anfrage aus der Datenbank gelesen; ein weiterer Containerneubau ist dafür nicht erforderlich. Bereits angezeigte Vorschläge ändern sich dadurch nicht. Wird die wirksame Anweisung während einer Anfrage geändert, wird deren Ergebnis verworfen.

Fest im Code bleiben das JSON-Antwortschema, erlaubte Felder und Stammdatennamen, Textbelege für Datum/Nummer, Behandlung des Dokumenttexts, der weiterhin fehlende Kategorie2-Vorschlag und die technische Validierung. Die fachlichen Hinweise werden mit dem Dokument und den Stammdaten als Kontext an Ollama übergeben; sie ersetzen diese technischen Vorgaben nicht. Auch eine klare Anweisung garantiert nicht, dass das lokale Modell jedes Dokument richtig zuordnet. Verbindliche Zuordnungen gehören weiterhin in AutoKat.

## Tatsächliche KI-Anfrage anzeigen

Die Diagnose ist eine optionale Wartungsfunktion und im normalen Arbeitsablauf ausgeblendet. Für bestehende Installationen nach Sicherung und Schreibpause `sql/update_ollama_diagnostics.sql` ausführen und das Web-Image aktualisieren. Neuinstallationen erhalten den Parameter aus `seed.sql`. Unter **Einstellungen → Entwicklerwerkzeuge → KI-Diagnose anzeigen** (`ollamaDiagnoseAnzeigen`) **Ja** wählen und speichern. Anschließend die Klassifizierungsseite neu öffnen; ungespeicherte Eingaben vorher sichern. Nur bei aktiver lokaler KI und eingeschalteter Diagnose erscheinen Menüeintrag und Diagnosebereich. **Nein** oder ein fehlender Parameter blendet beides aus. Die Einstellung steuert die Anzeige; die KI-Anfrage und ihre Vorschläge bleiben unverändert.

Nach **KI-Vorschlag erstellen** im Drei-Punkte-Menü **KI-Anfrage anzeigen** wählen. Die Anzeige enthält die zuletzt für `/api/chat` erfasste Anfrage dieser geöffneten Klassifizierungsseite: tatsächlich verwendeter Dokumenttext (einschließlich Kürzung), fachliche Anweisung, aktive Kategorien und Dokumententypen mit Erläuterungen, Formularwerte vor der Anfrage, feste Systemanweisung, Modell, Denkmodus, Kontext-/Ausgabelimits und Antwortschema.

Unter **Laufzeiten und Token-Zahlen** zeigt die Diagnose Ollamas Gesamtzeit, Modellladezeit, Eingabeverarbeitung und Generierungszeit in Sekunden sowie Eingabe-/Ausgabetokens, gegebenenfalls aus dem Cache gelesene Eingabetokens, Generierungsgeschwindigkeit und Abschlussgrund. Die Rohwerte werden in `diagnostic.metrics` unverändert als Nanosekunden bzw. Tokenzahlen weitergegeben. Generierung umfasst Denken und sichtbare Antwort gemeinsam; deren Zeiten werden nicht getrennt gemessen. Nicht gelieferte Messwerte erscheinen als „—“, gemeldete Nullwerte als 0. Die Gesamtzeit stammt von Ollama und umfasst nicht die gesamte Wartezeit im Browser. Auch eine unvollständige oder ungültige Modellantwort behält vorhandene Messwerte; bei Timeout ohne empfangene Antwort stehen keine Ollama-Werte bereit. Die vollständige Request-Diagnose bleibt auf die manuelle Anfrage dieser Seite beschränkt. Die Hintergrundverarbeitung speichert dagegen ausschließlich freigegebene numerische Messwerte je Durchlauf sowie Denkmodus, Ergebnisstatus, Endbestätigung und lokal gemessene Laufzeit; keine Anfragen, Antworten oder Gedankentexte. **JSON kopieren** kopiert weiterhin die gesendete Anfrage, keine Antwortmesswerte.

Der Inhalt wird unmittelbar vor dem Versand erfasst und anschließend mit der Antwort an den Browser zurückgegeben. Spätere Änderungen der Felder oder Stammdaten verändern diese Aufnahme nicht. Die Diagnose wird nicht separat gespeichert oder protokolliert und ist nach Neuladen nicht mehr verfügbar. Der Menüaufruf allein löst weder eine KI-Anfrage noch eine Feldänderung aus. Ohne erfasste Anfrage fordert die Anzeige zum ersten Abruf auf.

Auch bei fehlerhafter Modellantwort kann die erfasste Anfrage geprüft werden. Bei nicht bestätigtem Versand/Empfang wird dies angezeigt. Scheitert die Anfrage bereits vor der Generierung oder bricht die Browser-/Backendverbindung ab, kann keine neue Aufnahme angezeigt werden. Ein neuer Vorschlagsabruf ersetzt die vorherige Diagnose.

Unter **Gesendete Anfrage als JSON** kann der vollständige Request-Body über **JSON kopieren** kopiert werden. Ist die Zwischenablage im Browser nicht verfügbar, wird der Text markiert und kann mit Strg+C kopiert werden. Die lesbaren Abschnitte erleichtern die Prüfung, ob der erwartete Text und die Erläuterungen enthalten sind. **Schließen** blendet nur die Diagnose aus; Formularwerte bleiben erhalten.

### Dieselbe Anfrage direkt auf dem Ollama-PC testen

Zuerst den folgenden vollständigen Block in PowerShell starten. **Erst bei der Aufforderung** das Anfrage-JSON aus eablage kopieren und anschließend in PowerShell Enter drücken. So wird nicht versehentlich der Testcode aus der Zwischenablage gelesen. Der Test nutzt unverändert das Modell, die Anweisung, das Antwortschema und die Optionen aus der erfassten Anfrage; das Ziel ist der lokale Ollama-Dienst auf dem PC (bei abweichendem Port anpassen).

```powershell
& {
    Read-Host "Kopiere JETZT das Anfrage-JSON aus eablage. Danach hier Enter drücken"
    $json = Get-Clipboard -Raw
    $request = $json | ConvertFrom-Json
    if (!$request.model -or !$request.messages) {
        throw "Kein gültiges Ollama-Anfrage-JSON kopiert."
    }
    $result = Invoke-RestMethod `
        -Uri "http://localhost:11434/api/chat" `
        -Method Post `
        -ContentType "application/json; charset=utf-8" `
        -Body ([Text.Encoding]::UTF8.GetBytes($json)) `
        -TimeoutSec 300
    $result.message.content
    $result.done_reason
}
```

Die Modellantwort verwendet die exakten Namen der aktiven Kategorien und Dokumententypen. Python ordnet sie eindeutig den IDs zu; Oberfläche und Hintergrundcache verwenden weiterhin IDs. Ein Vergleich mit einer vereinfachten Anfrage erlaubt anschließend, Einflüsse von zusätzlichem Kontext oder Antwortschema einzugrenzen. Die Diagnose ergänzt keine automatische fachliche Korrektur.

### Hintergrund-KI wartet ohne Ergebnis

Das Worker-Protokoll mit `docker compose logs --tail 100 eablage-worker` prüfen. Es enthält bei Fehlern Typ, Fehlernummer und SQLSTATE, keine Dokumenttexte. SQL-Fehler 1267 (`Illegal mix of collations`) wurde durch kollationsunabhängige Binärvergleiche der Prüfsummen behoben. Dafür den aktuellen Worker neu bauen und bereitstellen; keine Schemaänderung und kein erneutes SQL-Update erforderlich. Der Worker übernimmt wartende Aufträge nach dem Neustart wieder. Ein funktionierender manueller Abruf bestätigt nicht, dass die Hintergrundwarteschlange fehlerfrei läuft.

### Übersichtliche Anzeige der KI-Informationen

KI-Verlauf und ergänzende Hinweise werden nicht zusätzlich am Formular oder in der Eingangsliste wiederholt. Das Öffnen der Erkennungsdetails ändert keine Formularwerte und löst keine neue KI-Anfrage aus. Neue KI-Hinweise öffnen die Erkennungsdetails nicht automatisch; ein ausdrücklicher Fehlerverweis oder der Menüaufruf **KI-Anfrage anzeigen** öffnet sie. Feldweise Vorschläge, Textbelege und Herkunftskennzeichnungen bleiben direkt an ihren Feldern. Das Ausblenden der Vorschläge verändert die gespeicherte Durchlaufhistorie nicht.

Für diese reine Anzeigeanpassung ist bei bereits aktuellem Backend/Worker nur das Webimage neu zu bauen; Datenbank und KI-Verarbeitung bleiben unverändert.


### Kompakte Bestätigung und Textbelege

Stimmt ein vorhandener Feldwert mit dem KI-Vorschlag überein, steht das Badge **KI bestätigt** neben dem Herkunftsbadge bei der Feldbezeichnung. Übernommene KI-Werte tragen weiterhin **KI**. Datums- und Nummernbelege sind am jeweiligen KI-Badge per Maus, Tastatur oder Antippen erreichbar; Escape oder Antippen außerhalb schließt den Tooltip. Abweichende Vorschläge bleiben unter dem Feld übernehmbar.

Die KI-Angaben in den Erkennungsdetails verwenden dieselben Hinweisboxen wie AutoKat und Textvergleich: Informationen mit Modell und Verlauf blau, ergänzende Warnungen und Fehler gelb. Die technische Anfrage-Diagnose bleibt separat aufklappbar.

Bei neu erstellten KI-Vorschlägen nennen Hinweise zu verworfenen Nummern zusätzlich den betroffenen Wert, damit fehlende Textbelege oder unbekannte beziehungsweise doppelte Nummernarten nachvollziehbar sind. Sehr lange Werte werden für die Anzeige nach 255 Zeichen gekürzt. Bereits zwischengespeicherte Hinweise ändern sich erst mit einem neuen Vorschlag.
