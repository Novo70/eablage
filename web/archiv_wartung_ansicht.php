<div class="panel panel-primary" id="archivpruefung">
    <div class="panel-heading">Archivprüfung &amp; Metadatenreparatur</div>
    <div class="panel-body">
        <p>Prüft die Zuordnung von Datenbankeinträgen, PDFs und Metadateien sowie vorhandene SHA-256-Prüfsummen. Die Prüfung verändert keine Dateien oder Referenzwerte. Die Datenbank ist für Metadaten maßgeblich. Das Einlesen aller PDFs mit Prüfsumme kann Zeit benötigen.</p>
        <p>Reparaturen erzeugen JSON-Metadateien aus den Datenbankwerten.</p>
        <form method="POST" action="gesamtexport.php#archivpruefung">
            <input type="hidden" name="action" value="archive_audit">
            <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
            <button type="submit" class="btn btn-primary">Archiv prüfen</button>
        </form>
        <?php if ($archiveError !== ''): ?>
            <p role="alert"><?=h($archiveError)?></p>
        <?php endif; ?>
        <?php if ($archiveRepair !== null): ?>
            <h3>Ergebnis der Reparatur</h3>
            <ul><?php foreach ($archiveRepair['results'] as $result): ?>
                <li><?=h('DokID ' . $result['dok_id'] . ': ' . ($result['success'] ? 'Erfolgreich – ' : 'Nicht repariert – ') . $result['message'])?></li>
            <?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if ($archiveReport !== null): ?>
            <p><strong><?=h($archiveReport['documents'])?></strong> Dokumente geprüft am <?=h($archiveReport['checked_at'])?>.
                <?=h(count($archiveReport['issues']))?> Befunde, davon <?=h($archiveReport['repairable'])?> Metadateien reparierbar.</p>
            <p>Davon <?=h($archiveReport['trash_documents'] ?? 0)?> Dokumente im Papierkorb; deren PDFs und Metadateien wurden mitgeprüft.</p>
            <p><?=h($archiveReport['checksums_checked'] ?? 0)?> PDF-Prüfsummen verglichen;
                <?=h($archiveReport['checksums_unknown'] ?? 0)?> Dokumente ohne Referenzprüfsumme (keine Integritätsaussage möglich).</p>
            <p>PDF-Verzeichnis: <code><?=h($archiveReport['archive_path'])?></code><br>Metadaten: <code><?=h($archiveReport['meta_path'])?></code></p>
            <?php if (empty($archiveReport['issues'])): ?>
                <p class="alert alert-success">Keine Abweichungen gefunden.</p>
            <?php else: ?>
                <form id="archive-repair-form" method="POST" action="gesamtexport.php#archivpruefung" onsubmit="return confirm('Ausgewählte JSON-Metadateien aus der Datenbank neu erzeugen? Vorhandene JSON-Inhalte werden ersetzt.');">
                    <input type="hidden" name="action" value="archive_repair">
                    <input type="hidden" name="aktion_token" value="<?=h($aktionToken)?>">
                    <?php if ($archiveReport['repairable'] > 0): ?>
                        <div class="archive-bulk-controls" hidden>
                            <button type="button" class="btn btn-default" data-archive-select="all">Alle auswählen</button>
                            <button type="button" class="btn btn-default" data-archive-select="none">Auswahl aufheben</button>
                            <span data-archive-count aria-live="polite"></span>
                        </div>
                    <?php endif; ?>
                    <div class="table-responsive">
                    <table class="table table-striped">
                        <thead><tr><th>Auswahl</th><th>DokID</th><th>Datei</th><th>Befund</th></tr></thead>
                        <tbody><?php foreach ($archiveReport['issues'] as $issue): ?>
                            <tr><td><?php if (isset($issue['token'])): ?>
                                <input type="checkbox" name="archive_selection[]" aria-label="Metadatei für DokID <?=h($issue['dok_id'])?> reparieren"
                                    value="<?=h(json_encode(['dok_id' => (int)$issue['dok_id'], 'token' => $issue['token']], JSON_THROW_ON_ERROR))?>">
                            <?php endif; ?></td>
                            <td><?=h($issue['dok_id'] ?? '–')?></td>
                            <td><?=h($issue['filename'])?></td>
                            <td><?=h(($issue['status'] === 'meta_different' ? 'Abweichende Felder: ' : '') . implode(', ', $issue['details']))?></td></tr>
                        <?php endforeach; ?></tbody>
                    </table>
                    </div>
                    <?php if ($archiveReport['repairable'] > 0): ?>
                        <p>Die Auswahl gilt <?=h($archiveReport['token_minutes'])?> Minuten und verfällt bei Änderungen an den betreffenden Daten. Größere Auswahlen werden mit JavaScript automatisch in Paketen bis 100 Dateien verarbeitet; ohne JavaScript sind höchstens 100 Dateien pro Start möglich. PDFs und Datenbankeinträge werden nicht verändert.</p>
                        <button type="submit" class="btn btn-warning">Ausgewählte Metadateien reparieren</button>
                        <p data-archive-progress role="status" aria-live="polite"></p>
                        <ul data-archive-results></ul>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
