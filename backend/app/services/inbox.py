"""Database-backed inbox jobs. A connection-scoped lock owns each file operation.

Crashes release the lock automatically. The next owner can recover 'in Verarbeitung'.
Only this service writes proposals; PHP marks completion in its archival transaction.
"""
from contextlib import contextmanager
import fcntl
import hashlib
import json
import logging
import os
from pathlib import Path
import stat

from app.db.database import get_connection
from app.services.archive_service import _root
from app.services.classification import classify
from app.services.pdf_text import extract, MAX_PDF

LOG = logging.getLogger(__name__)


class PendingPDFError(ValueError):
    """The source is still changing; retry after the next stability check."""


def settings(connection, database):
    cursor = connection.cursor(dictionary=True)
    cursor.execute('SELECT DATABASE() AS name')
    if cursor.fetchone()['name'] != database:
        raise ValueError('Webanwendung und Backend verwenden unterschiedliche Datenbanken.')
    cursor.execute("SELECT param_key, param_value FROM parameter WHERE param_key IN ('pfadAblageNeu_linux', 'pfadAblage_linux', 'PDFTextMode')")
    values = {row['param_key']: row['param_value'] for row in cursor.fetchall()}
    connection.commit()
    return values


def file_key(database, filename):
    return hashlib.sha256((database + '\n' + filename).encode()).hexdigest()


def lock_name(database, filename):
    return 'eablage.inbox.' + file_key(database, filename)[:40]


def file_path(values, filename, scope='inbox'):
    if not filename or len(filename) > 255 or Path(filename).name != filename or '\\' in filename or any(ord(c) < 32 or ord(c) == 127 for c in filename) or not filename.lower().endswith('.pdf'):
        raise ValueError('Ungültiger PDF-Dateiname.')
    key = 'pfadAblageNeu_linux' if scope == 'inbox' else 'pfadAblage_linux'
    root = _root(values.get(key), key)
    path = root / filename
    if path.is_symlink():
        raise ValueError('Verknüpfungen sind als Eingangsdateien nicht zulässig.')
    return path


def read_pdf(path):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, 'rb') as stream:
        fcntl.flock(stream.fileno(), fcntl.LOCK_SH | fcntl.LOCK_NB)
        before = os.fstat(stream.fileno())
        if not stat.S_ISREG(before.st_mode) or before.st_size > MAX_PDF:
            raise ValueError('PDF ist keine reguläre Datei oder überschreitet 50 MiB.')
        raw = stream.read(MAX_PDF + 1)
        after = os.fstat(stream.fileno())
    current = path.stat(follow_symlinks=False)
    identity = lambda s: (s.st_dev, s.st_ino, s.st_size, s.st_mtime_ns, s.st_ctime_ns)
    if identity(before) != identity(after) or identity(after) != identity(current) or len(raw) != before.st_size:
        raise PendingPDFError('PDF wird noch übertragen oder wurde geändert. Bitte später erneut versuchen.')
    return raw, hashlib.sha256(raw).hexdigest()


@contextmanager
def file_lock(connection, database, filename):
    cursor = connection.cursor()
    name = lock_name(database, filename)
    cursor.execute('SELECT GET_LOCK(%s, 0)', (name,))
    acquired = cursor.fetchone()[0] == 1
    try:
        yield acquired
    finally:
        if acquired:
            cursor.execute('SELECT RELEASE_LOCK(%s)', (name,))
            cursor.fetchone()


def job_result(row):
    return dict(id=row['id'], sha256=row['sha256'], status=row['status'], error=row['fehler'] or '',
                result=json.loads(row['ergebnis']) if row['ergebnis'] else None, document_id=row['dok_id'])


def record_read_failure(connection, database, filename, error, waiting=False):
    # An empty hash explicitly means no safely read content and no proposals.
    # The file lock and restore gate are held by the caller.
    cursor = connection.cursor(dictionary=True)
    key = file_key(database, filename)
    cursor.execute('SELECT * FROM verarbeitungsauftraege WHERE dateischluessel=%s', (key,))
    previous = cursor.fetchone()
    if previous and previous['status'] == 'archiviert':
        # Only a successfully read, different hash may reopen a completed job.
        return job_result(previous)
    status = 'wartend' if waiting else 'fehler'
    cursor.execute("""INSERT INTO verarbeitungsauftraege
        (dateischluessel,dateiname,sha256,status,versuche,fehler)
        VALUES (%s,%s,'',%s,1,%s)
        ON DUPLICATE KEY UPDATE sha256='',status=VALUES(status),
        versuche=versuche+1,fehler=VALUES(fehler),ergebnis=NULL,dok_id=NULL""",
        (key, filename, status, error))
    connection.commit()
    cursor.execute('SELECT * FROM verarbeitungsauftraege WHERE dateischluessel=%s', (key,))
    return job_result(cursor.fetchone())


def process(database, filename, retry=False, ocr_requested=False):
    retry = retry or ocr_requested
    connection = get_connection()
    gate = 'eablage.inbox.gate.' + hashlib.sha256(database.encode()).hexdigest()[:40]
    gate_held = False
    try:
        guard = connection.cursor()
        guard.execute('SELECT GET_LOCK(%s, 0)', (gate,))
        gate_held = guard.fetchone()[0] == 1
        if not gate_held:
            return dict(status='verarbeitung', result=None)

        values = settings(connection, database)
        path = file_path(values, filename)
        with file_lock(connection, database, filename) as acquired:
            if not acquired:
                return dict(status='verarbeitung', result=None)
            try:
                raw, digest = read_pdf(path)
            except (BlockingIOError, PendingPDFError, FileNotFoundError):
                return record_read_failure(connection, database, filename,
                    'PDF wird noch übertragen oder ist vorübergehend nicht verfügbar. Erkennung wird erneut versucht.', waiting=True)
            except (ValueError, OSError) as exc:
                LOG.warning('Eingangsdatei nicht lesbar: %s: %s', filename, exc)
                error = str(exc) if isinstance(exc, ValueError) else 'PDF konnte nicht gelesen werden. Leserechte und Datenträger prüfen.'
                return record_read_failure(connection, database, filename, error)
            cursor = connection.cursor(dictionary=True)
            key = file_key(database, filename)
            cursor.execute('SELECT * FROM verarbeitungsauftraege WHERE dateischluessel=%s', (key,))
            row = cursor.fetchone()
            if row and row['sha256'] == digest and row['status'] in ('bereit', 'archiviert', 'fehler') and not (retry and row['status'] in ('fehler', 'bereit')):
                return job_result(row)
            if row:
                cursor.execute("UPDATE verarbeitungsauftraege SET versuche=IF(sha256=%s,versuche+1,1), sha256=%s, status='verarbeitung', ergebnis=NULL, fehler=NULL, dok_id=NULL WHERE id=%s", (digest, digest, row['id']))
                job_id = row['id']
            else:
                cursor.execute("INSERT INTO verarbeitungsauftraege (dateischluessel,dateiname,sha256,status,versuche) VALUES (%s,%s,%s,'verarbeitung',1)", (key, filename, digest))
                job_id = cursor.lastrowid
            connection.commit()  # processing state visible while file lock remains held
            guard.execute('SELECT RELEASE_LOCK(%s)', (gate,))
            guard.fetchone()
            gate_held = False
            pdf = None
            try:
                pdf = extract(raw, values.get('PDFTextMode', 'layout'), ocr_requested=ocr_requested)
                result = dict(pdf=pdf, classification=classify(pdf['text']))
                _, current_digest = read_pdf(path)
                if current_digest != digest:
                    raise ValueError('PDF wurde zwischenzeitlich geändert. Bitte erneut klassifizieren.')
                state = 'bereit' if pdf['ok'] else 'fehler'
                error = pdf['error']
            except Exception as exc:
                LOG.exception('Inbox-Verarbeitung fehlgeschlagen: %s', filename)
                result = dict(pdf=pdf, classification=classify('')) if pdf else None
                state, error = 'fehler', str(exc) if isinstance(exc, (ValueError, FileNotFoundError)) else 'Verarbeitung fehlgeschlagen. Backend-Protokoll prüfen und erneut versuchen.'
            cursor.execute('UPDATE verarbeitungsauftraege SET status=%s, ergebnis=%s, fehler=%s WHERE id=%s',
                           (state, json.dumps(result, ensure_ascii=False) if result else None, error, job_id))
            connection.commit()
            return dict(id=job_id, sha256=digest, status=state, error=error, result=result, document_id=None)
    finally:
        connection.close()


def list_jobs(database, filenames=None):
    connection = get_connection()
    try:
        values = settings(connection, database)
        if filenames is None:
            root = _root(values.get('pfadAblageNeu_linux'), 'pfadAblageNeu_linux')
            filenames = [p.name for p in root.iterdir() if p.suffix.lower() == '.pdf' and not p.is_symlink() and p.is_file()]
        keys = list(dict.fromkeys(file_key(database, name) for name in filenames))
        cursor = connection.cursor(dictionary=True)
        rows = []
        for offset in range(0, len(keys), 200):
            batch = keys[offset:offset + 200]
            cursor.execute('SELECT id,dateiname,sha256,status,fehler,dok_id FROM verarbeitungsauftraege WHERE dateischluessel IN (' + ','.join(['%s'] * len(batch)) + ')', tuple(batch))
            rows.extend(cursor.fetchall())
        return {'jobs': rows}
    finally:
        connection.close()


def extract_document(database, filename, scope, ocr_requested=False):
    connection = get_connection()
    try:
        values = settings(connection, database)
        raw, _ = read_pdf(file_path(values, filename, scope))
        return extract(raw, values.get('PDFTextMode', 'layout'), ocr_requested)
    finally:
        connection.close()


def reconcile_missing(database, root):
    """Forget derived text for externally removed inbox files, never delete PDFs."""
    connection = get_connection()
    try:
        cursor = connection.cursor(dictionary=True)
        cursor.execute("SELECT id,dateiname FROM verarbeitungsauftraege WHERE status NOT IN ('archiviert','verworfen','entfernt')")
        rows = cursor.fetchall()
        connection.commit()
        for row in rows:
            path = root / row['dateiname']
            if path.exists():
                continue
            with file_lock(connection, database, row['dateiname']) as acquired:
                if acquired and not path.exists():
                    cursor.execute("UPDATE verarbeitungsauftraege SET status='entfernt',ergebnis=NULL,fehler=NULL WHERE id=%s AND status NOT IN ('archiviert','verworfen')", (row['id'],))
                    connection.commit()
    finally:
        connection.close()
