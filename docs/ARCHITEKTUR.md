# Architektur- und Entwicklungsleitlinien für eablage

## Zielsetzung

eablage ist eine lokal betriebene Einbenutzer-Anwendung. Die technische Architektur soll deshalb robust, nachvollziehbar und wartbar bleiben, ohne unnötige Komplexität einzuführen.

## Evolutionäre Weiterentwicklung

eablage wird evolutionär weiterentwickelt. Bestehende, stabile Komponenten werden nicht allein aus technologischen oder architektonischen Gründen ersetzt.

Neue Technologien, zusätzliche Dienste und Migrationen werden eingesetzt, wenn sie einen konkreten funktionalen, qualitativen, sicherheitsbezogenen oder wartungsbezogenen Vorteil bieten.

Wird eine Komponente ohnehin wesentlich verändert, soll geprüft werden, ob dabei eine Verbesserung im Sinne der langfristigen Architektur sinnvoll und mit vertretbarem Aufwand umgesetzt werden kann.

## PHP und Python/FastAPI

Die bestehende PHP-Webanwendung und das Python-/FastAPI-Backend dürfen langfristig parallel bestehen.

FastAPI übernimmt insbesondere fachlich abgegrenzte Funktionen, für die Python technische oder strukturelle Vorteile bietet. Eine vollständige Migration der PHP-Anwendung nach Python ist kein Selbstzweck und besitzt keinen festgelegten Zieltermin.

Migrationen erfolgen bevorzugt nach vollständigen fachlichen Vorgängen und nicht durch die willkürliche Verlagerung einzelner Funktionen.

Für einen migrierten Vorgang soll die fachliche Verantwortung eindeutig einer Komponente zugeordnet sein. Insbesondere soll es für schreibende Vorgänge eine eindeutige Schreibhoheit geben.

## Angemessene technische Komplexität

Technische Lösungen sollen sich an den tatsächlichen Anforderungen von eablage orientieren.

Zusätzliche Abstraktionsschichten, Dienste oder Infrastruktur werden nicht allein deshalb eingeführt, weil sie bei größeren Anwendungen üblich oder Stand der Technik sind.

Performanceoptimierungen sollen grundsätzlich auf beobachteten oder absehbaren Engpässen beruhen. Messbare Probleme haben Vorrang vor hypothetischen Skalierungsproblemen.

## Qualität und Datensicherheit

Die bewusste Begrenzung technischer Komplexität bedeutet keine Reduzierung der Qualitätsanforderungen.

Besondere Bedeutung haben:

- Integrität und Sicherheit des Dokumentenarchivs,
- zuverlässige Datenbanksicherung und Wiederherstellung,
- nachvollziehbare Fehlerbehandlung,
- konsistente Datenänderungen und Transaktionen,
- Schutz vor unbeabsichtigtem Überschreiben oder Löschen,
- Regressionstests für kritische Funktionen und bekannte Fehlerfälle.

Bei diesen Punkten hat Robustheit Vorrang vor einer möglichst einfachen Implementierung.

## Entscheidungsprinzip

Bei größeren technischen Änderungen sollen insbesondere folgende Fragen betrachtet werden:

1. Welches konkrete Problem wird gelöst?
2. Welchen praktischen Vorteil erhält eablage dadurch?
3. Welche zusätzliche Komplexität und Wartung entstehen?
4. Kann die bestehende Lösung sinnvoll weiterverwendet werden?
5. Wenn die Komponente ohnehin geändert wird: Lässt sich dabei mit vertretbarem Aufwand die langfristige Architektur verbessern?

Eine technische Änderung sollte durchgeführt werden, wenn ihr erwarteter Nutzen die dadurch entstehende zusätzliche Komplexität für den tatsächlichen Einsatz von eablage rechtfertigt.

## Versionierung und Dokumentation

Webanwendung und Backend werden gemeinsam versioniert und zusammen getestet. `main` enthält den freigegebenen Stand; `develop` enthält die Weiterentwicklung. Die manuelle Instanzkennzeichnung `Version` in der Datenbank ersetzt keinen Commit- oder Versionsnachweis.

Markdown ist die einzige redaktionelle Dokumentationsquelle. Änderungen am Verhalten werden im gleichen Änderungsvorgang an der zuständigen Dokumentationsseite gepflegt. Die [Dokumentationsübersicht](README.md) führt zu Installation, Bedienung, Betrieb und Referenz. Historische Prüfergebnisse und überholte Anleitungen gehören nicht in die aktuelle Dokumentation; die Git-Historie bewahrt frühere Fassungen. Die Changelogs bleiben eigenständige Änderungshistorien.

Aktuell implementierte Zuständigkeiten, Schnittstellen und Speichergrenzen stehen in der [technischen Referenz](TECHNIK.md). Die Leitlinien beschreiben Entwicklungsentscheidungen und setzen keine noch nicht implementierte Migration voraus.
