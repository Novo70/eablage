"""Read-only archive audit and explicitly selected metadata repairs.

The database is authoritative. PDFs and database rows are never changed.
"""
import hashlib
import hmac
import json
import logging
import os
from pathlib import Path
import secrets
import stat
import tempfile
import time

from app.services.metadata_service import (
    MAX_METADATA_BYTES, MetadataError, UnsupportedMetadataVersion, build_metadata,
    metadata_filename, parse_metadata, serialize_metadata,
    pdf_sha256, pdf_filename,
)

_DOCUMENT_SELECT = '''SELECT d.*, k1.katBez, k2.katBez AS kat2Bez, dt.doktypBez
    FROM dokumente d
    LEFT JOIN kategorie k1 ON k1.id = d.kategorie_id
    LEFT JOIN kategorie k2 ON k2.id = d.kategorie2_id
    LEFT JOIN doktyp dt ON dt.id = d.doktyp_id'''

_SECRET = secrets.token_bytes(32)
_TOKEN_TTL = 1800
_MAX_META = MAX_METADATA_BYTES
_LOG = logging.getLogger(__name__)


class ArchiveError(ValueError):
    pass


def _connection():
    from app.db.database import get_connection
    return get_connection()


def _root(value, name):
    path = Path(str(value or ''))
    if not value or not path.is_absolute():
        raise ArchiveError(f'{name}: absoluter Containerpfad fehlt.')
    allowed = Path(os.getenv('ARCHIVE_DATA_ROOT', '/data')).resolve(strict=True)
    try:
        resolved = path.resolve(strict=True)
        resolved.relative_to(allowed)
    except (OSError, ValueError) as exc:
        raise ArchiveError(f'{name}: Verzeichnis fehlt oder liegt außerhalb von {allowed}.') from exc
    if resolved == allowed or not resolved.is_dir() or not os.access(resolved, os.R_OK | os.X_OK):
        raise ArchiveError(f'{name}: Verzeichnis ist nicht lesbar oder kein eigener Unterordner.')
    return resolved


def _settings(cursor, database):
    cursor.execute('SELECT DATABASE() AS name')
    if cursor.fetchone()['name'] != database:
        raise ArchiveError('Webanwendung und Backend verwenden unterschiedliche Datenbanken.')
    cursor.execute('SELECT param_key, param_value FROM parameter WHERE param_key IN (%s, %s)',
                   ('pfadAblage_linux', 'pfadMetadaten_linux'))
    settings = {row['param_key']: row['param_value'] for row in cursor.fetchall()}
    archive = _root(settings.get('pfadAblage_linux'), 'pfadAblage_linux')
    meta = _root(settings.get('pfadMetadaten_linux'), 'pfadMetadaten_linux')
    if archive == meta or archive in meta.parents or meta in archive.parents:
        raise ArchiveError('Archiv und Metadaten müssen getrennte Verzeichnisse sein.')
    return archive, meta


def _read(path):
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except FileNotFoundError:
        return None
    with os.fdopen(fd, 'rb') as stream:
        info = os.fstat(stream.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_size > _MAX_META:
            raise ArchiveError('Keine reguläre Metadatei oder Datei größer als 16 MiB.')
        return stream.read(_MAX_META + 1)


def _hash_pdf(path):
    """Stream an existing regular PDF; reject links and concurrent changes."""
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, 'rb') as stream:
        before = os.fstat(stream.fileno())
        if not stat.S_ISREG(before.st_mode):
            raise ArchiveError('Keine reguläre PDF-Datei.')
        digest = hashlib.sha256()
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            digest.update(block)
        after = os.fstat(stream.fileno())
        current = os.stat(path, follow_symlinks=False)
        def identity(info):
            return (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns)
        if identity(before) != identity(after) or identity(after) != identity(current):
            raise ArchiveError('PDF wurde während der Prüfung geändert.')
        return digest.hexdigest()


def _fingerprint(document, raw, database, meta):
    data = [database, str(meta), serialize_metadata(document),
            None if raw is None else hashlib.sha256(raw).hexdigest()]
    return hashlib.sha256(json.dumps(data, ensure_ascii=False).encode()).hexdigest()


def _token(fingerprint, issued=None):
    issued = str(int(time.time()) if issued is None else issued)
    digest = hmac.new(_SECRET, (issued + ':' + fingerprint).encode(), hashlib.sha256).hexdigest()
    return issued + ':' + digest


def _verify(token, fingerprint):
    try:
        issued = int(token.split(':')[0])
        if not 0 <= time.time() - issued <= _TOKEN_TTL:
            return False
        return hmac.compare_digest(token, _token(fingerprint, issued))
    except (ValueError, TypeError, AttributeError):
        return False


def _meta_status(document, raw):
    expected = build_metadata(document)
    if raw is None:
        return 'meta_missing', ['JSON-Metadatei fehlt']
    try:
        actual = parse_metadata(raw)
    except UnsupportedMetadataVersion:
        return 'meta_unsupported', ['Unbekannte JSON-Formatversion; keine automatische Reparatur']
    except MetadataError:
        return 'meta_invalid', ['JSON-Format oder UTF-8-Kodierung ungültig']
    changed = [key for key in expected if key != 'schema_version'
               and actual.get(key) != expected[key]]
    return ('meta_different', changed) if changed else ('ok', [])


def _pdf_name(document):
    try:
        return pdf_filename(document['DokID'])
    except (MetadataError, KeyError):
        return None


def audit(database):
    connection = _connection()
    try:
        cursor = connection.cursor(dictionary=True)
        archive, meta = _settings(cursor, database)
        # Enumerate first: inaccessible mounts must fail, never appear empty.
        pdf_entries = list(archive.iterdir())
        meta_entries = list(meta.iterdir())
        cursor.execute(_DOCUMENT_SELECT + ' ORDER BY d.DokID')
        documents = cursor.fetchall()
        issues = []
        expected_pdf, expected_meta = set(), set()
        checksums_checked = 0
        checksums_unknown = 0
        for document in documents:
            doc_id = int(document['DokID'])
            reference_hash = document.get('DateiSHA256')
            if reference_hash is None:
                checksums_unknown += 1
            name = _pdf_name(document)
            if name:
                expected_pdf.add(name)
                path = archive / name
                if path.is_symlink() or (path.exists() and not path.is_file()):
                    issues.append(dict(dok_id=doc_id, status='pdf_invalid', filename=name, details=['Keine reguläre PDF-Datei']))
                elif not path.exists():
                    issues.append(dict(dok_id=doc_id, status='pdf_missing', filename=name, details=['PDF fehlt']))
                elif not os.access(path, os.R_OK):
                    issues.append(dict(dok_id=doc_id, status='pdf_unreadable', filename=name, details=['PDF nicht lesbar']))
                elif reference_hash is not None:
                    try:
                        pdf_sha256(reference_hash)
                        actual_hash = _hash_pdf(path)
                        checksums_checked += 1
                        if not hmac.compare_digest(actual_hash, reference_hash):
                            issues.append(dict(dok_id=doc_id, status='pdf_hash_mismatch', filename=name,
                                               details=['SHA-256 weicht vom gespeicherten Referenzwert ab; PDF prüfen. Keine automatische Reparatur.']))
                    except MetadataError:
                        issues.append(dict(dok_id=doc_id, status='pdf_hash_invalid', filename=name,
                                           details=['SHA-256-Referenzwert in der Datenbank ungültig']))
                    except (OSError, ArchiveError):
                        issues.append(dict(dok_id=doc_id, status='pdf_unreadable', filename=name,
                                           details=['PDF konnte nicht sicher auf Integrität geprüft werden']))
            else:
                issues.append(dict(dok_id=doc_id, status='pdf_invalid', filename='', details=['DokID in der Datenbank ungültig']))
            meta_name = metadata_filename(doc_id)
            expected_meta.add(meta_name)
            checked_name = meta_name
            try:
                raw = _read(meta / meta_name)
                status, details = _meta_status(document, raw)
                if status != 'ok':
                    issue = dict(dok_id=doc_id, status=status, filename=meta_name, details=details)
                    if status != 'meta_unsupported':
                        issue['token'] = _token(_fingerprint(document, raw, database, meta))
                    issues.append(issue)
            except MetadataError as exc:
                issues.append(dict(dok_id=doc_id, status='meta_source_invalid', filename=meta_name,
                                   details=['Datenbankwerte nicht für JSON geeignet: ' + str(exc)]))
            except (OSError, ArchiveError) as exc:
                issues.append(dict(dok_id=doc_id, status='meta_unreadable', filename=checked_name,
                                   details=['Metadatei nicht sicher lesbar; Berechtigungen/Dateityp prüfen']))
        for entries, expected, suffix, status in [(pdf_entries, expected_pdf, '.pdf', 'pdf_orphan'),
                                                   (meta_entries, expected_meta, ('meta.txt', 'meta.json'), 'meta_orphan')]:
            for path in sorted(entries):
                if path.name.lower().endswith(suffix) and path.name not in expected:
                    issues.append(dict(dok_id=None, status=status, filename=path.name, details=['Kein zugehöriger Datenbankeintrag']))
        return dict(database=database, archive_path=str(archive), meta_path=str(meta),
                    documents=len(documents), trash_documents=sum(d.get('GeloeschtAm') is not None for d in documents),
                    issues=issues, repairable=sum('token' in i for i in issues),
                    checksums_checked=checksums_checked, checksums_unknown=checksums_unknown,
                    checked_at=time.strftime('%Y-%m-%d %H:%M:%S'), token_minutes=_TOKEN_TTL // 60)
    finally:
        connection.close()


def repair(database, selections):
    results = []
    # A separate transaction per item allows partial results and row locking against edits.
    for item in selections:
        connection = _connection()
        temp = None
        try:
            cursor = connection.cursor(dictionary=True)
            archive, meta = _settings(cursor, database)
            cursor.execute(_DOCUMENT_SELECT + ' WHERE d.DokID = %s FOR UPDATE', (item['dok_id'],))
            document = cursor.fetchone()
            if not document:
                raise ArchiveError('Dokument existiert nicht mehr. Bitte erneut prüfen.')
            target = meta / metadata_filename(int(document['DokID']))
            raw = _read(target)
            if not _verify(item['token'], _fingerprint(document, raw, database, meta)):
                raise ArchiveError('Prüfergebnis abgelaufen oder Daten/Datei geändert. Bitte erneut prüfen.')
            status = _meta_status(document, raw)[0]
            if status in {'ok', 'meta_unsupported'}:
                raise ArchiveError('Metadatei ist aktuell oder verwendet eine nicht unterstützte Formatversion.')
            content = serialize_metadata(document).encode('utf-8')
            if len(content) > _MAX_META:
                raise ArchiveError('JSON-Metadatei überschreitet das Größenlimit.')
            fd, temp = tempfile.mkstemp(prefix='.eablage-repair-', dir=meta)
            with os.fdopen(fd, 'wb') as stream:
                stream.write(content)
                stream.flush()
                os.fsync(stream.fileno())
                os.fchmod(stream.fileno(), 0o664)
            # Recheck external filesystem changes before atomic replacement.
            if _read(target) != raw:
                raise ArchiveError('Metadatei wurde zwischenzeitlich geändert. Bitte erneut prüfen.')
            if raw is None:
                os.link(temp, target)  # Exclusive publication; preserve concurrent external files.
                os.unlink(temp)
            else:
                os.replace(temp, target)
            temp = None
            results.append(dict(dok_id=item['dok_id'], success=True, message='JSON-Metadatei neu erzeugt.'))
            _LOG.info('Archivwartung: Metadatei repariert; DB=%s DokID=%s', database, item['dok_id'])
        except (ArchiveError, MetadataError, OSError) as exc:
            _LOG.warning('Archivwartung DokID=%s: %s', item['dok_id'], exc)
            message = str(exc) if isinstance(exc, (ArchiveError, MetadataError)) else 'Dateizugriff fehlgeschlagen; Mount und Schreibrechte prüfen.'
            results.append(dict(dok_id=item['dok_id'], success=False, message=message))
        finally:
            if temp:
                os.unlink(temp)
            # No database writes; release row locks, even after successful file replacement.
            connection.rollback()
            connection.close()
    return dict(results=results)
