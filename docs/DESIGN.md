# Darstellung und Design-System

eAblage verwendet ein einziges responsives Layout. Es richtet sich nach zwei unabhängigen Kriterien:

| Kriterium | Erkennung | Wirkung |
| --- | --- | --- |
| Platz | Fensterbreite ab 1280 Pixeln | Navigation als Seitenleiste links, Inhalte über die volle Breite |
| Eingabeart | `(hover: hover) and (pointer: fine)` | Mit Maus oder Touchpad kompakte Schrift, Abstände und Steuerelemente; bei Touch große Schaltflächen |

Unter **Verwaltung → Darstellung** lässt sich die Eingabeart pro Browser festlegen: **Auto** (Standard), **Kompakt** oder **Touch**. Die Wahl wird im `localStorage` unter `eablage.density` gespeichert und setzt `data-ea-density` am `html`-Element. Für Tests und Links wirkt auch der URL-Parameter `?density=auto|compact|touch`; der frühere Parameter `?layout=desktop|touch` wird als `compact` bzw. `touch` übernommen.

Ab 1600 Pixeln Fensterbreite kann in den zweigeteilten Dokumentansichten die Vorschau verbreitert werden (`eablage.previewSplit`).

## Aufbau von `web/app.css`

1. **Tokens:** Palette, semantische Farben, Schrift, Abstände, Radien, Schatten, Layoutmaße
2. **Dichte:** die einzigen Größen, die sich zwischen Touch und Kompakt unterscheiden
3. **Basis:** Grundelemente wie `body`, Links und Fokusrahmen
4. **Layout und Navigation:** Raster, Kopfleiste, Seitenleiste, Menü, Darstellungswahl
5. **Grundkomponenten:** Panels, Reiter, Formulare, Schaltflächen, Tabellen, Kennzeichnungen, Meldungen
6. **Seitenbereiche:** seitenspezifische Anordnungen
7. **Hilfsklassen:** Abstände (`ea-mt-1`, `ea-mt-2`, `ea-mb-0`), Textfarben (`text-muted`, `text-warning`, `text-danger`), `small`, `ea-break-anywhere`

## Regeln für Erweiterungen

- Farben nur über semantische Tokens wie `--ea-text`, `--ea-text-muted`, `--ea-border`, `--ea-surface`, `--ea-primary` sowie die Statusfarben `--ea-info-*`, `--ea-success-*`, `--ea-warning-*`, `--ea-danger-*` und `--ea-ai-*` setzen.
- Schriftgrößen nur über die Skala `--ea-text-xs`, `--ea-text-sm`, `--ea-text-md`, `--ea-text-lg`, `--ea-text-xl`.
- Steuerelemente nutzen `--ea-control-h` bzw. `--ea-control-h-sm`, damit sie in beiden Bedienarten passen.
- Abstände über `--ea-space-*`, Innenabstände von Panels über `--ea-panel-pad`.
- Radien über `--ea-radius-sm`, `--ea-radius`, `--ea-radius-lg`, `--ea-control-radius` und `--ea-radius-pill`.
- Vollhohe Arbeitsflächen verwenden `--ea-workspace-height`.
- Keine `style`-Attribute und keine `<style>`-Blöcke in PHP-Seiten. Seitenköpfe entstehen über `eablage_seitenkopf()` aus `web/seitenkopf.php`.
- Die Panelköpfe sind weiß; Titel, Symbol und 3 px Linie stehen in der Primärfarbe. Zusätze im Titel wie die Trefferzahl bleiben grau (`--ea-text-muted`). Ihr Aussehen hängt an `--ea-panel-head-bg`, `--ea-panel-head-text` und `--ea-panel-head-accent` (Linie unter dem Kopf).
- Meldungen entstehen über `eablage_render_message()` mit SVG-Symbol aus `icons.svg`. In der Oberfläche werden keine Emojis als Symbole verwendet.
- Aktiv/Inaktiv-Schalter in Listen verwenden `.ea-status-toggle` mit `data-active="1|0"`.
- Vollhohe Arbeitsflächen nutzen die Komponente `.ea-workspace` mit `.ea-workspace-scroll` für den scrollbaren Inhalt. Seitenspezifische Klassen ergänzen nur Innenabstände und Fußleisten.
- Tabellenköpfe sind hellblau wie der aktive Menüpunkt (`--ea-table-head-bg`, `--ea-table-head-text`). Eine ausgewählte Ergebniszeile hat zusätzlich links die 3 px Markierung in der Primärfarbe, damit sie sich vom Tabellenkopf abhebt.
- Verschachtelte Panels gelten als Unterabschnitte: Titel in Textfarbe, ohne Akzentlinie und Schatten.
- Anklickbare Aktionen in Formularen und Filterleisten sind Schaltflächen (`.btn`), keine Textlinks. „Filtern“/„Suchen“ ist `.btn-primary` mit Lupe, „Zurücksetzen“ daneben `.btn-default`.
- Fußleisten mit Schaltflächen sind hell (`--ea-footer-bg`) und oben mit derselben 3 px Linie abgesetzt wie die Panelköpfe (`--ea-footer-border`). Das gilt auch auf dem Smartphone, wo die Fußleisten im Seitenfluss mitlaufen statt fest unten zu stehen.
