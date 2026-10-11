"""Run with python worker.py. One bounded job at a time, interruptible between jobs."""
import logging
import os
import signal
import threading
import time
from app.db.database import get_connection
from app.services.inbox import settings, file_path, process, reconcile_missing, list_jobs
from app.services.archive_service import _root

LOG = logging.getLogger('eablage.worker')
stop = threading.Event()


def scan(previous, completed=None):
    completed = completed if completed is not None else {}
    database = os.environ['DB_NAME']
    connection = get_connection()
    try:
        values = settings(connection, database)
    finally:
        connection.close()
    root = _root(values.get('pfadAblageNeu_linux'), 'pfadAblageNeu_linux')
    reconcile_missing(database, root)
    jobs = {row['dateiname']: row for row in list_jobs(database)['jobs']}
    observed = {}
    for path in sorted(root.iterdir()):
        if stop.is_set():
            break
        if path.suffix.lower() != '.pdf' or path.is_symlink() or not path.is_file():
            continue
        try:
            file_path(values, path.name)
            info = path.stat()
            signature = (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns)
            observed[path.name] = signature
            # External SMB copies must be unchanged over two scans and at least 15s old.
            if previous.get(path.name) != signature or time.time() - info.st_mtime < 15:
                continue
            row = jobs.get(path.name)
            cached = completed.get(path.name)
            if row and cached == (signature, row['id'], row['sha256'], row['status']):
                continue
            completed.pop(path.name, None)
            result = process(database, path.name)
            if result['status'] in ('bereit', 'fehler', 'archiviert') and result.get('sha256'):
                after = path.stat()
                current = (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns)
                if current == signature:
                    completed[path.name] = (signature, result['id'], result['sha256'], result['status'])
            if result['status'] == 'fehler':
                LOG.warning('%s: %s', path.name, result.get('error'))
        except Exception:
            LOG.exception('Datei konnte nicht verarbeitet werden: %s', path.name)
    for name in list(completed):
        if name not in observed:
            del completed[name]
    return observed


def main():
    logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
    for sig in (signal.SIGINT, signal.SIGTERM):
        signal.signal(sig, lambda *_: stop.set())
    interval = max(5, min(3600, int(os.getenv('EABLAGE_INBOX_INTERVAL', '60'))))
    previous, completed = {}, {}
    LOG.info('Inbox-Worker gestartet; Prüfintervall %ss.', interval)
    while not stop.is_set():
        if os.getenv('EABLAGE_INBOX_ENABLED', 'true').lower() not in ('0', 'false', 'off', 'no'):
            try:
                previous = scan(previous, completed)
            except Exception:
                LOG.exception('Inbox-Prüfung fehlgeschlagen; nächster Versuch im nächsten Intervall.')
        stop.wait(interval)

if __name__ == '__main__':
    main()
