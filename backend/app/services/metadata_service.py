"""Pure JSON metadata format shared by save, edit and archive maintenance."""
from datetime import date, datetime
import json
import re

SCHEMA_VERSION = 3
SUPPORTED_SCHEMA_VERSIONS = (1, 2, 3)
V2_FIELDS = {'Originaldateiname': 'original_filename', 'DateiSHA256': 'pdf_sha256',
             'Aenderungsdatum': 'modified_at'}
MAX_METADATA_BYTES = 16 * 1024 * 1024
TEXT_FIELDS = {
    'Partner': 'partner', 'Betreff': 'subject', 'Nummer': 'number',
    'Tags': 'tags', 'Doktext': 'document_text',
}
LOOKUPS = {
    'category': ('kategorie_id', 'katBez'),
    'category2': ('kategorie2_id', 'kat2Bez'),
    'document_type': ('doktyp_id', 'doktypBez'),
}
REQUIRED_FIELDS = frozenset({
    'DokID', 'Datum', 'Archivierungsdatum', 'Aufbewahrungsdauer',
    *TEXT_FIELDS, *V2_FIELDS, *(field for pair in LOOKUPS.values() for field in pair),
})


class MetadataError(ValueError):
    """Incomplete or invalid source data; callers must not write a partial file."""


class UnsupportedMetadataVersion(MetadataError):
    """Do not overwrite formats from a different application generation."""


def _text(value, field):
    if value is None or value == '':
        return None
    if not isinstance(value, str):
        raise MetadataError(f'{field}: Text oder null erwartet.')
    try:
        value.encode('utf-8')
    except UnicodeError as exc:
        raise MetadataError(f'{field}: ungültiger Unicode-Text.') from exc
    return value


def _integer(value, field, minimum):
    if type(value) is not int or value < minimum:
        raise MetadataError(f'{field}: ganze Zahl ab {minimum} erwartet.')
    return value


def _date(value):
    if value is None or value == '':
        return None
    if type(value) is date:
        return value.isoformat()
    if isinstance(value, str) and re.fullmatch(r'\d{4}-\d{2}-\d{2}', value):
        try:
            return date.fromisoformat(value).isoformat()
        except ValueError:
            pass
    raise MetadataError('Datum: gültiges ISO-Datum YYYY-MM-DD erwartet.')


def _reserved_retention(value):
    # Reserved DB column: preserve integers without assigning business semantics.
    if value is not None and type(value) is not int:
        raise MetadataError('Aufbewahrungsdauer: ganze Zahl oder null erwartet.')
    return value


def _timestamp(value, field='Archivierungsdatum'):
    if value is None or value == '':
        return None
    if isinstance(value, str):
        if not re.fullmatch(r'\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}', value):
            raise MetadataError(f'{field}: Datum mit Uhrzeit erwartet.')
        try:
            value = datetime.fromisoformat(value)
        except ValueError as exc:
            raise MetadataError(f'{field}: ungültiger Zeitpunkt.') from exc
    if not isinstance(value, datetime) or value.tzinfo is not None or value.microsecond:
        raise MetadataError(f'{field}: zeitzonenloser DB-Wert mit Sekunden erwartet.')
    return value.isoformat(timespec='seconds')


def pdf_filename(document_id):
    """Canonical archive basename; never derived from stored filesystem paths."""
    return f"{_integer(document_id, 'DokID', 1)}dok.pdf"


def original_filename(value):
    """Preserve the incoming basename, never an incoming filesystem path."""
    value = _text(value, 'Originaldateiname')
    if value is not None and (len(value) > 255 or len(value) <= 4
            or not value.lower().endswith('.pdf') or '/' in value or '\\' in value
            or any(ord(char) < 32 or ord(char) == 127 for char in value)):
        raise MetadataError('Originaldateiname: PDF-Dateiname ohne Pfad erwartet (maximal 255 Zeichen).')
    return value


def pdf_sha256(value):
    if value is not None and (not isinstance(value, str)
            or re.fullmatch(r'[0-9a-f]{64}', value) is None):
        raise MetadataError('DateiSHA256: 64 kleine Hexadezimalzeichen oder null erwartet.')
    return value


def build_metadata(document):
    """Convert a complete DB-shaped row plus explicit lookup labels to schema v3.

    No DB/filesystem access. Additional DB columns are ignored. Missing keys fail,
    while explicit None denotes absent data. Lookup IDs remain strings.
    """
    missing = REQUIRED_FIELDS.difference(document)
    if missing:
        raise MetadataError('Fehlende Felder: ' + ', '.join(sorted(missing)))
    result = {
        'schema_version': SCHEMA_VERSION,
        'document_id': _integer(document['DokID'], 'DokID', 1),
        'pdf_filename': pdf_filename(document['DokID']),
        'document_date': _date(document['Datum']),
        'archived_at': _timestamp(document['Archivierungsdatum']),
        'original_filename': original_filename(document['Originaldateiname']),
        'pdf_sha256': pdf_sha256(document['DateiSHA256']),
        'modified_at': _timestamp(document['Aenderungsdatum'], 'Aenderungsdatum'),
        'retention_duration': _reserved_retention(document['Aufbewahrungsdauer']),
    }
    for target, (id_field, label_field) in LOOKUPS.items():
        identifier = _text(document[id_field], id_field)
        label = _text(document[label_field], label_field)
        if identifier is None and label is not None:
            raise MetadataError(f'{label_field}: Bezeichnung ohne ID.')
        result[target] = {'id': identifier, 'label': label}
    for source, target in TEXT_FIELDS.items():
        result[target] = _text(document[source], source)
    return result


def serialize_metadata(document):
    """Return deterministic, readable JSON text; caller encodes it as UTF-8."""
    content = json.dumps(build_metadata(document), ensure_ascii=False,
                         allow_nan=False, indent=2) + '\n'
    if len(content.encode('utf-8')) > MAX_METADATA_BYTES:
        raise MetadataError('JSON-Metadatei überschreitet 16 MiB.')
    return content


def metadata_filename(document_id):
    return f"{_integer(document_id, 'DokID', 1)}meta.json"


def parse_metadata(raw):
    """Read schemas 1, 2 and 3, preserving the version and validating exact keys."""
    def unique_object(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise MetadataError('Doppelter JSON-Schlüssel: ' + key)
            result[key] = value
        return result

    try:
        value = json.loads(raw.decode('utf-8-sig'), object_pairs_hook=unique_object)
        if not isinstance(value, dict):
            raise MetadataError('JSON-Objekt erwartet.')
        version = value.get('schema_version')
        if type(version) is not int:
            raise MetadataError('Ganzzahlige Formatversion fehlt.')
        if version not in SUPPORTED_SCHEMA_VERSIONS:
            raise UnsupportedMetadataVersion('Unbekannte Formatversion; keine automatische Reparatur.')
        source = {
            'DokID': value['document_id'],
            'Datum': value['document_date'], 'Archivierungsdatum': value['archived_at'],
            'Aufbewahrungsdauer': value['retention_duration'],
        }
        for source_field, target in V2_FIELDS.items():
            source[source_field] = value[target] if version >= 2 else None
        for target, (id_field, label_field) in LOOKUPS.items():
            lookup = value[target]
            if not isinstance(lookup, dict) or set(lookup) != {'id', 'label'}:
                raise MetadataError('Ungültige Zuordnung: ' + target)
            source[id_field], source[label_field] = lookup['id'], lookup['label']
        for source_field, target in TEXT_FIELDS.items():
            source[source_field] = value[target]
        normalized = build_metadata(source)
        normalized['schema_version'] = version
        if version < 3:
            normalized['info'] = _text(value['info'], 'Info')
        if version == 1:
            for target in V2_FIELDS.values():
                del normalized[target]
        if set(value) != set(normalized) or value != normalized:
            raise MetadataError('JSON entspricht nicht dem Formatvertrag.')
        return normalized
    except (UnicodeError, ValueError, KeyError, TypeError, RecursionError) as exc:
        if isinstance(exc, MetadataError):
            raise
        raise MetadataError('Ungültige JSON-Metadaten.') from exc
