import re
from datetime import date, datetime

from app.db.database import get_connection
from app.services.metadata_service import original_filename


PROFILE_FULL = 1
PROFILE_SINGLE = 2
MAX_EXPORT_DIRECTORY_LEVELS = 5
_GERMAN_MONTHS = (
    "Januar", "Februar", "März", "April", "Mai", "Juni",
    "Juli", "August", "September", "Oktober", "November", "Dezember",
)


def _is_enabled(value) -> bool:
    return str(value or "").strip().lower() in {"ja", "true", "1"}


def _clean_filename_part(value) -> str:
    """Return a portable, filesystem-safe representation of one filename/path part."""
    text = str(value or "").strip()
    if not text:
        return ""

    text = re.sub(r'[<>:"/\\|?*]', "-", text)
    text = re.sub(r'[\x00-\x1f\x7f]', "-", text)
    text = re.sub(r"\s+", " ", text)
    text = re.sub(r"\s*-\s*-+\s*", " - ", text)
    return text.strip(" .-")


def _fit_filename_stem(stem: str, suffix: str = "") -> str:
    """Allow at most 255 UTF-8 bytes including suffix and .pdf extension."""
    budget = 255 - len((suffix + ".pdf").encode("utf-8"))
    stem = stem.encode("utf-8")[:budget].decode("utf-8", errors="ignore").rstrip(" .-")
    return (stem or "Dokument") + suffix


def _unique_export_names(items: list[dict]) -> None:
    """Preserve names where possible; avoid collisions, also on Windows shares."""
    def key(item, filename):
        return tuple(str(part).casefold() for part in [*item["directories"], filename])

    reserved = {key(item, item["filename"]) for item in items}
    used = set()
    for item in items:
        original = item["filename"]
        candidate = original
        sequence = 0
        while key(item, candidate) in used or (sequence > 0 and key(item, candidate) in reserved):
            sequence += 1
            suffix = f" - DokID {item['dok_id']}" + (f"-{sequence}" if sequence > 1 else "")
            candidate = _fit_filename_stem(original[:-4], suffix) + ".pdf"
        item["filename"] = candidate
        used.add(key(item, candidate))


def load_export_settings(profile: int = PROFILE_SINGLE) -> dict:
    """Load one export profile from the parameter table."""
    prefix = f"{profile}_exp"
    connection = get_connection()
    try:
        cursor = connection.cursor(dictionary=True)
        cursor.execute(
            """
            SELECT param_key, param_value
            FROM parameter
            WHERE param_key LIKE %s
            """,
            (f"{prefix}%",),
        )
        rows = cursor.fetchall()
        return {
            row["param_key"][len(f"{profile}_"):]: row["param_value"]
            for row in rows
        }
    finally:
        connection.close()


def _document_select_sql(where_clause: str = "") -> str:
    return f"""
        SELECT
            d.DokID,
            d.Datum,
            d.Originaldateiname,
            d.kategorie_id,
            d.kategorie2_id,
            d.doktyp_id,
            d.Partner,
            d.Betreff,
            d.Nummer,
            d.Tags,
            k1.katBez,
            k2.katBez AS kat2Bez,
            dt.doktypBez
        FROM dokumente d
        LEFT JOIN kategorie k1 ON k1.id = d.kategorie_id
        LEFT JOIN kategorie k2 ON k2.id = d.kategorie2_id
        LEFT JOIN doktyp dt ON dt.id = d.doktyp_id
        {where_clause}
    """


def load_document(dok_id: int) -> dict:
    """Load one archived document and lookup values needed for export."""
    connection = get_connection()
    try:
        cursor = connection.cursor(dictionary=True)
        cursor.execute(
            _document_select_sql("WHERE d.DokID = %s AND d.GeloeschtAm IS NULL") + " LIMIT 1",
            (dok_id,),
        )
        row = cursor.fetchone()
        if not row:
            raise LookupError(f"Dokument mit DokID {dok_id} wurde nicht gefunden.")
        return row
    finally:
        connection.close()


def load_documents() -> list[dict]:
    """Load all archived documents in stable DokID order."""
    connection = get_connection()
    try:
        cursor = connection.cursor(dictionary=True)
        cursor.execute(_document_select_sql("WHERE d.GeloeschtAm IS NULL") + " ORDER BY d.DokID")
        return cursor.fetchall()
    finally:
        connection.close()


def build_export_filename(document: dict, settings: dict) -> str:
    """Build the human-readable export filename used by the web application."""
    mode = str(settings.get('expDateinameModus') or 'generiert').strip().lower()
    if mode not in {'generiert', 'original'}:
        raise ValueError('expDateinameModus: generiert oder original erwartet.')
    incoming = original_filename(document.get('Originaldateiname')) if mode == 'original' else None
    fields = (
        ("expDokID", document.get("DokID")),
        ("expDatum", document.get("Datum")),
        ("expKategorieID", document.get("kategorie_id")),
        ("expKategorie", document.get("katBez")),
        ("expKategorie2ID", document.get("kategorie2_id")),
        ("expKategorie2", document.get("kat2Bez")),
        ("expDoktypID", document.get("doktyp_id")),
        ("expDoktyp", document.get("doktypBez")),
        ("expPartner", document.get("Partner")),
        ("expBetreff", document.get("Betreff")),
        ("expNummer", document.get("Nummer")),
        ("expTags", document.get("Tags")),
    )

    parts = []
    for setting_name, value in fields:
        if not _is_enabled(settings.get(setting_name)):
            continue
        cleaned = _clean_filename_part(value)
        if cleaned:
            parts.append(cleaned)

    if not parts:
        parts.append(str(document["DokID"]))

    filename = (_clean_filename_part(incoming[:-4]) if incoming else '') or " - ".join(parts)

    try:
        max_length = int(settings.get("expMaxLaengeDateiname") or 250)
    except (TypeError, ValueError):
        max_length = 250

    max_length = max(1, max_length)
    filename = _fit_filename_stem(filename[:max_length])
    # Check after truncation, which can itself produce a reserved device name.
    if re.fullmatch(r'(?i:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\..*)?', filename):
        filename = _fit_filename_stem('_' + filename)

    return f"{filename}.pdf"


def _document_date(value) -> date | None:
    """Accept database dates or ISO dates; never guess missing/invalid dates."""
    if isinstance(value, datetime):
        return value.date()
    if isinstance(value, date):
        return value
    if isinstance(value, str) and re.fullmatch(r"\d{4}-\d{2}-\d{2}", value):
        try:
            return date.fromisoformat(value)
        except ValueError:
            pass
    return None


def build_export_directories(document: dict, settings: dict) -> list[str]:
    """Build the configured relative directory structure for one full-export item."""
    if not _is_enabled(settings.get("expVerzeichnisstruktur")):
        return []

    document_date = _document_date(document.get("Datum"))
    values = {
        "Kategorie": document.get("katBez"),
        "Kategorie2": document.get("kat2Bez"),
        "Dokumententyp": document.get("doktypBez"),
        "Partner": document.get("Partner"),
        "Nummer": document.get("Nummer"),
        "Jahr": f"{document_date.year:04d}" if document_date else None,
        "Monat": (f"{document_date.month:02d} - {_GERMAN_MONTHS[document_date.month - 1]}"
                  if document_date else None),
    }

    directories = []
    for level in range(1, MAX_EXPORT_DIRECTORY_LEVELS + 1):
        setting = str(settings.get(f"expVerzeichnisEbene{level}") or "").strip()
        if setting in {"", "(keine)", "nein"}:
            continue
        cleaned = _clean_filename_part(values.get(setting))
        if cleaned:
            directories.append(cleaned)

    return directories


def prepare_single_export(dok_id: int) -> dict:
    """Return export metadata; filesystem access remains the web server's task."""
    document = load_document(dok_id)
    settings = load_export_settings(PROFILE_SINGLE)

    return {
        "dok_id": dok_id,
        "filename": build_export_filename(document, settings),
        "profile": PROFILE_SINGLE,
    }


def build_full_export_plan(documents: list[dict], settings: dict) -> dict:
    """Shared, pure plan builder for full export and the future virtual explorer.

    Resolve filename collisions over the complete document set before filtering
    for a view. Stable DokID ordering keeps names independent of input order.
    """
    items = []
    for document in sorted(documents, key=lambda document: document["DokID"]):
        items.append(
            {
                "dok_id": document["DokID"],
                "filename": build_export_filename(document, settings),
                "directories": build_export_directories(document, settings),
                "display": {
                    "date": document.get("Datum"),
                    "category": document.get("katBez"),
                    "type": document.get("doktypBez"),
                    "partner": document.get("Partner"),
                    "subject": document.get("Betreff"),
                    "number": document.get("Nummer"),
                },
            }
        )

    _unique_export_names(items)

    return {
        "profile": PROFILE_FULL,
        "profile_name": settings.get("expParameterBezeichnung") or "Gesamtexport",
        "directory_structure": _is_enabled(settings.get("expVerzeichnisstruktur")),
        "count": len(items),
        "items": items,
    }


def prepare_full_export() -> dict:
    """Load current full-export parameters and documents, without file access."""
    return build_full_export_plan(load_documents(), load_export_settings(PROFILE_FULL))
