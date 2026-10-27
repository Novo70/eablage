"""Reproducible inbox AI cache; one generation lane shared with manual requests."""
import hashlib
import json
from app.db.database import get_connection
from app.services import ollama
from app.services.ollama_history import safe_attempts, describe_attempts

MODES = ('aus', 'fehlende', 'alle')


def mode(connection, database):
    cursor = connection.cursor(dictionary=True)
    cursor.execute('SELECT DATABASE() AS name')
    if cursor.fetchone()['name'] != database:
        raise ollama.OllamaError('Webanwendung und Backend verwenden unterschiedliche Datenbanken.')
    cursor.execute("SELECT param_key,param_value FROM parameter WHERE param_key IN ('ollamaAktiv','ollamaHintergrund')")
    values = {r['param_key']: r['param_value'] for r in cursor.fetchall()}
    connection.commit()
    value = values.get('ollamaHintergrund', 'aus')
    if value not in MODES:
        raise ollama.OllamaError('Ungültige Einstellung für KI im Hintergrund.')
    return value if values.get('ollamaAktiv') == 'ja' else 'aus'


def eligible(classification, value):
    if value == 'alle':
        return True
    if value != 'fehlende':
        return False
    auto = classification.get('autokat') or {}
    comparison = classification.get('textcompare') or {}
    classified = (classification.get('autokat_ok') and
                  (auto.get('matched_classification_rule_ref') or auto.get('matched_classification_rule_refs')))
    compared = (classification.get('textcompare_ok') and comparison.get('matched') and
                str(comparison.get('category') or '0').strip() not in ('', '0'))
    return not (classified or compared)


def revision(raw):
    return hashlib.sha256(raw.encode()).hexdigest()


def context_signature(context):
    keys = ('source_sha256', 'revision', 'url', 'model', 'thinking', 'instruction', 'categories', 'types',
            'categories_explanations', 'types_explanations', 'number_types', 'number_types_explanations')
    values = {k: context.get(k) for k in keys}
    values['prompt_version'] = 'typed-numbers-v1'
    if ollama.text_window(context) != (ollama.DEFAULT_TEXT_HEAD, ollama.DEFAULT_TEXT_TAIL):
        values['text_window'] = ollama.text_window(context)
    return revision(json.dumps(values, ensure_ascii=False, sort_keys=True))


def current_fields(classification):
    limits = {**ollama.FIELDS, 'category2': 15}
    # Automatic values can exceed interactive bounds; exclude those from AI context.
    fields = {k: v for k, limit in limits.items() if isinstance(v := classification.get(k), str)
            and len(v) <= limit and not any(ord(c) < 32 for c in v)}
    if 'numbers' in classification:
        fields['numbers'] = [{'kind_id':str(n['kind_id']),'value':n['value']} for n in classification.get('numbers',[])]
    return fields


def retryable_failure(raw):
    """Legacy/transient errors remain retryable; terminal failures carry no raw text."""
    try:
        data = json.loads(raw or '{}')
    except (TypeError, ValueError):
        return True
    return not isinstance(data, dict) or data.get('retryable') is not False


def run_one(database, on_start=None):
    """Returns idle/done/failed/stopped; a separate worker thread invokes this serially.

    No file or inbox gate is held during inference: normal processing/archival stays
    available. Revision-conditional writes and Ollama's latest-context check reject
    results after OCR, archival, file replacement or configuration changes.
    """
    connection = get_connection()
    try:
        value = mode(connection, database)
        if value == 'aus':
            return 'idle'  # Older installations need no new cache table while disabled.
        head, tail = ollama.load_text_settings(connection)
        selection_key = f'{head}:{tail}'
        connection.commit()
        with ollama.generation_lock(database) as acquired:
            if not acquired:
                return 'idle'
            cursor = connection.cursor(dictionary=True)
            cursor.execute("""DELETE k FROM ki_vorschlaege k LEFT JOIN verarbeitungsauftraege v ON v.id=k.auftrag_id
                WHERE v.id IS NULL OR v.status<>'bereit'""")
            connection.commit()
            # SHA2 inherits the connection collation. Compare hash bytes, not
            # strings with a potentially incompatible ASCII/UTF-8 collation.
            cursor.execute("""SELECT v.id,k.ergebnis AS ki_ergebnis,k.kontext AS ki_kontext FROM verarbeitungsauftraege v
                LEFT JOIN ki_vorschlaege k ON k.auftrag_id=v.id
                WHERE v.status='bereit' AND v.ergebnis IS NOT NULL AND
                (k.auftrag_id IS NULL OR k.textfenster IS NULL OR CAST(k.textfenster AS BINARY)<>CAST(%s AS BINARY) OR CAST(k.revision AS BINARY)<>CAST(SHA2(v.ergebnis,256) AS BINARY) OR CAST(k.source_sha256 AS BINARY)<>CAST(v.sha256 AS BINARY) OR k.status IN ('verarbeitung','veraltet')
                OR (k.status='fehler' AND k.geaendert_am<DATE_SUB(NOW(), INTERVAL 5 MINUTE)))
                ORDER BY CASE WHEN k.status='fehler' THEN 1 ELSE 0 END,v.id""", (selection_key,))
            rows = cursor.fetchall()
            connection.commit()
            for candidate in rows:
                cursor.execute('SELECT id,dateiname,sha256,status,ergebnis FROM verarbeitungsauftraege WHERE id=%s', (candidate['id'],))
                row = cursor.fetchone()
                connection.commit()
                if not row or row['status'] != 'bereit' or not row['ergebnis']:
                    continue
                result = json.loads(row['ergebnis'])
                text = result.get('pdf', {}).get('text', '')
                classification = result.get('classification') or {}
                if not text.strip() or not eligible(classification, value):
                    continue
                if not retryable_failure(candidate.get('ki_ergebnis')):
                    current = ollama.load_context(database, row['dateiname'], row['sha256'], row['id'])
                    if candidate.get('ki_kontext') == context_signature(current):
                        continue
                rev = revision(row['ergebnis'])
                cursor.execute("""INSERT INTO ki_vorschlaege (auftrag_id,revision,source_sha256,textfenster,status)
                    VALUES (%s,%s,%s,%s,'verarbeitung') ON DUPLICATE KEY UPDATE revision=VALUES(revision),source_sha256=VALUES(source_sha256),textfenster=VALUES(textfenster),
                    status='verarbeitung',ergebnis=NULL,fehler=NULL,kontext=NULL,geaendert_am=NOW()""", (row['id'], rev, row['sha256'], selection_key))
                connection.commit()
                if on_start is not None:
                    on_start(row['id'])
                state, cached, error, signature = 'bereit', None, None, None
                retryable = True
                attempts = []
                try:
                    context = ollama.load_context(database, row['dateiname'], row['sha256'], row['id'])
                    if context['revision'] != rev or ollama.window_key(context) != selection_key:
                        raise ollama.OllamaError('Der Dokumententwurf wurde geändert.')
                    signature = context_signature(context)
                    proposal = ollama._suggest(database, row['dateiname'], row['sha256'], row['id'],
                                               hashlib.sha256(text.encode()).hexdigest(), current_fields(classification))
                    # Request diagnostics and document text are never persisted in the AI cache.
                    attempts = safe_attempts(proposal.get('attempts'))
                    cached_data = {k: proposal[k] for k in ('model', 'proposal', 'warnings', 'labels')}
                    cached_data['attempts'] = attempts
                    cached = json.dumps(cached_data, ensure_ascii=False)
                    if value != mode(connection, database):
                        raise ollama.OllamaError('Die Hintergrund-KI-Einstellung wurde geändert.')
                except Exception as exc:
                    state = 'fehler'
                    retryable = getattr(exc, 'retryable', True)
                    diagnostic = getattr(exc, 'diagnostic', None)
                    if isinstance(diagnostic, dict):
                        attempts = diagnostic.get('attempts', attempts)
                    cached = json.dumps({'retryable': retryable, 'attempts': safe_attempts(attempts)})
                    error = str(exc) if isinstance(exc, ollama.OllamaError) else 'Hintergrund-KI fehlgeschlagen. Einstellungen und Erreichbarkeit prüfen.'
                cursor.execute("""UPDATE ki_vorschlaege k JOIN verarbeitungsauftraege v ON v.id=k.auftrag_id
                    SET k.status=%s,k.ergebnis=%s,k.fehler=%s,k.kontext=%s,k.geaendert_am=NOW()
                    WHERE k.auftrag_id=%s AND CAST(k.revision AS BINARY)=CAST(%s AS BINARY) AND v.status='bereit'
                    AND CAST(v.sha256 AS BINARY)=CAST(%s AS BINARY) AND CAST(SHA2(v.ergebnis,256) AS BINARY)=CAST(%s AS BINARY)""",
                    (state, cached, error, signature, row['id'], rev, row['sha256'], rev))
                connection.commit()
                return ('failed' if retryable else 'stopped') if state == 'fehler' else 'done'
            return 'idle'
    finally:
        connection.close()


def read(database, filename, sha256, job_id, text_sha256, current_fields=None):
    """Read-only retrieval never calls Ollama; stale cache is scheduled for refresh."""
    connection = get_connection()
    try:
        value = mode(connection, database)
        if value == 'aus':
            return {'status': 'aus'}
        # Pending status polls read prepared data only. Read/hash the PDF and full
        # current master context once a usable cached proposal is actually present.
        cursor = connection.cursor(dictionary=True)
        cursor.execute('SELECT id,sha256,status,ergebnis FROM verarbeitungsauftraege WHERE dateischluessel=%s',
                       (ollama.file_key(database, filename),))
        job = cursor.fetchone()
        if not job or job['id'] != job_id or job['sha256'] != sha256 or job['status'] != 'bereit' or not job['ergebnis']:
            raise ollama.OllamaError('Der Dokumententwurf wurde geändert. Bitte den Eingang neu öffnen.')
        result = json.loads(job['ergebnis'])
        text = result.get('pdf', {}).get('text', '')
        if not isinstance(text, str) or hashlib.sha256(text.encode()).hexdigest() != text_sha256:
            raise ollama.OllamaError('Der angezeigte Dokumenttext wurde geändert. Bitte den Eingang neu öffnen.')
        classification = result.get('classification') or {}
        rev = revision(job['ergebnis'])
        if not eligible(classification, value):
            return {'status': 'aus'}
        cursor.execute('SELECT * FROM ki_vorschlaege WHERE auftrag_id=%s', (job_id,))
        cached = cursor.fetchone()
        connection.commit()
        limits = ollama.load_text_settings(connection)
        if not cached or cached['revision'] != rev or cached['source_sha256'] != sha256 or cached.get('textfenster') != f'{limits[0]}:{limits[1]}':
            return {'status': 'wartend'}
        if cached['status'] == 'bereit':
            context = ollama.load_context(database, filename, sha256, job_id)
            if context['revision'] != rev:
                raise ollama.OllamaError('Der Dokumententwurf wurde geändert. Bitte den Eingang neu öffnen.')
            if cached['kontext'] != context_signature(context):
                cursor.execute("UPDATE ki_vorschlaege SET status='veraltet' WHERE auftrag_id=%s AND CAST(revision AS BINARY)=CAST(%s AS BINARY) AND status='bereit'", (job_id, context['revision']))
                connection.commit()
                return {'status': 'wartend'}
            data = json.loads(cached['ergebnis'])
            data['attempts'] = safe_attempts(data.get('attempts'))
            data['attempt_history'] = describe_attempts(data['attempts'])
            data['proposal'], warnings = ollama.validate_proposal(data['proposal'], context, context['text'])
            data['warnings'] += warnings
            # Labels come from the current master data, never stale stored labels.
            data['labels'] = {'category': context['categories'].get(data['proposal']['category'], ''),
                              'document_type': context['types'].get(data['proposal']['document_type'], '')}
            return {'status': 'bereit', 'suggestion': data}
        if cached['status'] == 'fehler':
            retryable = retryable_failure(cached.get('ergebnis'))
            if not retryable:
                context = ollama.load_context(database, filename, sha256, job_id)
                if cached['kontext'] != context_signature(context):
                    cursor.execute("UPDATE ki_vorschlaege SET status='veraltet' WHERE auftrag_id=%s AND CAST(revision AS BINARY)=CAST(%s AS BINARY) AND status='fehler'", (job_id, context['revision']))
                    connection.commit()
                    return {'status': 'wartend'}
            history_data = json.loads(cached.get('ergebnis') or '{}')
            return {'status': 'fehler', 'error': cached['fehler'], 'retryable': retryable,
                    'attempt_history': describe_attempts(history_data.get('attempts'))}
        return {'status': 'wartend'}
    finally:
        connection.close()

