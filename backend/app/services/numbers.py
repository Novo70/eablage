"""Typed document numbers. One value per kind, no fallback to later anchors."""
import re
from app.services.text_extractor import find_search_position, extract_text_after


def search_value(value):
    return re.sub('[^a-zA-Z0-9]', '', value).upper()


def extract_number(text, definition):
    anchor = str(definition.get('Startanker') or '').strip()
    fixed = str(definition.get('Festwert') or '').strip()
    if not anchor:
        return fixed
    if definition.get('Endmodus') == 'legacy':
        return extract_text_after(text, anchor, definition.get('Praefix') or '',
                                  int(definition.get('MaxLaenge') or 0), definition.get('Endanker') or '') or fixed
    match = find_search_position(text, anchor)
    if match is None:
        return ''
    # Never strip a newline before restricting extraction to the anchor's line.
    value = re.split(r'[\r\n]', text[match[1]:], maxsplit=1)[0].lstrip(' \t:')
    mode = definition.get('Endmodus', 'zeile')
    if mode == 'leerzeichen':
        value = re.split(r'\s', value, maxsplit=1)[0]
    elif mode == 'anker':
        end = find_search_position(value, definition.get('Endanker') or '')
        if end is None:
            return ''
        value = value[:end[0]]
    # Count from the value, after the anchor's horizontal padding/colon.
    # Internal spaces are part of the number and count towards the limit.
    length = int(definition.get('MaxLaenge') or 0)
    if length > 0:
        value = value[:length]
    value = value.strip()
    return value if 0 < len(value) <= 255 and not any(ord(c) < 32 for c in value) else ''


def read_numbers(cursor, document_id):
    cursor.execute('''SELECT n.NummernartID AS kind_id, a.Bezeichnung AS label,
        n.Wert AS value, n.Sortierung AS position FROM dokument_nummer n
        JOIN nummernart a ON a.id=n.NummernartID
        WHERE n.DokumentID=%s ORDER BY n.Sortierung,n.ID''', (document_id,))
    return cursor.fetchall()


def attach_numbers(cursor, documents, active_only=False):
    """Load numbers in one query for archive audits, exports and text comparison."""
    cursor.execute("""SELECT n.DokumentID, n.NummernartID AS kind_id,
        a.Bezeichnung AS label, n.Wert AS value, n.Sortierung AS position
        FROM dokument_nummer n JOIN nummernart a ON a.id=n.NummernartID """
        + ('WHERE a.Aktiv=1 ' if active_only else '')
        + 'ORDER BY n.DokumentID,n.Sortierung,n.ID')
    grouped = {}
    for number in cursor.fetchall():
        identifier = number.pop('DokumentID')
        grouped.setdefault(identifier, []).append(number)
    for document in documents:
        document['numbers'] = grouped.get(document['DokID'], [])
