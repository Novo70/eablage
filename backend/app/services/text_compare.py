import re
from typing import Any

from app.db.database import get_connection


STOPWORDS = {
    "aber", "alle", "als", "also", "am", "an", "auch", "auf", "aus",
    "bei", "bin", "bis", "bist", "da", "dadurch", "daher", "darum", "das",
    "dass", "dein", "deine", "dem", "den", "der", "des", "die", "dies",
    "diese", "diesem", "diesen", "dieser", "doch", "dort", "du", "durch",
    "ein", "eine", "einem", "einen", "einer", "eines", "er", "es", "für",
    "hat", "hatte", "haben", "hier", "ich", "im", "in", "ist", "ja", "jede",
    "jedem", "jeden", "jeder", "jedes", "kann", "kein", "keine", "mit", "muss",
    "nach", "nicht", "noch", "nun", "oder", "sich", "sie", "sind", "so", "über",
    "um", "und", "uns", "unser", "unter", "vom", "von", "vor", "war", "waren",
    "was", "weil", "wenn", "wie", "wieder", "wir", "wird", "wo", "zu", "zum", "zur"
}


def _load_parameters() -> dict[str, str]:
    connection = get_connection()

    try:
        cursor = connection.cursor(dictionary=True)
        cursor.execute(
            """
            SELECT param_key, param_value
            FROM parameter
            WHERE LOWER(param_key) IN ('schwuebereinst', 'txtlaengevergl', 'minlaengevergl')
            """
        )
        rows = cursor.fetchall()
        # Preserve existing database keys (including the seed's SchwUebereinst)
        # while exposing one consistent spelling to the comparison logic.
        canonical_keys = {key.casefold(): key for key in (
            "schwUebereinst", "txtLaengeVergl", "minLaengeVergl"
        )}
        return {
            canonical_keys[str(row["param_key"]).casefold()]: str(row["param_value"] or "")
            for row in rows
        }
    finally:
        connection.close()


def _as_int(value: Any, default: int) -> int:
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


def _normalize_words(text: str) -> set[str]:
    """
    Normalisiert einen Dokumenttext für den Vergleich.

    - Unicode/Groß-Kleinschreibung über casefold()
    - nur Wörter/Ziffern berücksichtigen
    - Wörter unter 3 Zeichen ignorieren
    - häufige deutsche Stopwörter ignorieren
    - Mehrfachvorkommen nur einmal werten
    """
    if not text:
        return set()

    words = re.findall(r"[\wäöüß]+", text.casefold(), flags=re.UNICODE)

    return {
        word
        for word in words
        if len(word) >= 3 and word not in STOPWORDS
    }


def _similarity(search_text: str, comparison_text: str) -> tuple[int, int, int]:
    """
    Liefert (Prozent, gemeinsame Wörter, relevante Wörter des Suchtexts).

    Der Prozentwert beantwortet die fachliche Frage:
    Welcher Anteil der relevanten Wörter des neuen Dokuments kommt auch
    im bereits archivierten Dokument vor?
    """
    search_words = _normalize_words(search_text)
    comparison_words = _normalize_words(comparison_text)

    if not search_words or not comparison_words:
        return 0, 0, len(search_words)

    matching_words = search_words & comparison_words
    percent = round((len(matching_words) / len(search_words)) * 100)

    return percent, len(matching_words), len(search_words)


def _load_documents() -> list[dict]:
    connection = get_connection()

    try:
        cursor = connection.cursor(dictionary=True)
        cursor.execute(
            """
            SELECT
                DokID,
                Doktext,
                CASE WHEN EXISTS (SELECT 1 FROM kategorie k WHERE k.id = dokumente.kategorie_id AND k.Aktiv = 1) THEN kategorie_id ELSE NULL END AS kategorie_id,
                CASE WHEN EXISTS (SELECT 1 FROM kategorie k WHERE k.id = dokumente.kategorie2_id AND k.Aktiv = 1) THEN kategorie2_id ELSE NULL END AS kategorie2_id,
                CASE WHEN EXISTS (SELECT 1 FROM doktyp dt WHERE dt.id = dokumente.doktyp_id AND dt.Aktiv = 1) THEN doktyp_id ELSE NULL END AS doktyp_id,
                partner,
                betreff,
                Nummer,
                tags
            FROM dokumente
            WHERE GeloeschtAm IS NULL
              AND Doktext IS NOT NULL
              AND Doktext <> ''
            ORDER BY DokID ASC
            """
        )
        return cursor.fetchall()
    finally:
        connection.close()


def analyze_text_compare(text: str, exclude_document_id: int | None = None) -> dict:
    """
    Sucht in bereits archivierten Dokumenten nach der ähnlichsten Vorlage.

    Die Parameter werden aus der bestehenden Tabelle `parameter` gelesen:
    - schwUebereinst: Mindest-Ähnlichkeit in Prozent
    - txtLaengeVergl: maximale Zahl der Zeichen, die verglichen werden
    - minLaengeVergl: Mindestlänge des neuen Dokumenttexts
    """
    result = {
        "matched": False,
        "similarity": 0,
        "matching_words": 0,
        "relevant_words": 0,
        "source_document_id": None,
        "category": "",
        "category2": "",
        "document_type": "",
        "partner": "",
        "subject": "",
        "number": "",
        "tags": "",
        "reason": "no_match",
    }

    if not text:
        result["reason"] = "empty_text"
        return result

    params = _load_parameters()
    threshold = _as_int(params.get("schwUebereinst"), 70)
    compare_length = _as_int(params.get("txtLaengeVergl"), 5000)
    min_length = _as_int(params.get("minLaengeVergl"), 100)

    if compare_length <= 0:
        compare_length = 5000
    if min_length < 0:
        min_length = 0

    current_text = text[:compare_length]

    if len(current_text) < min_length:
        result["reason"] = "text_too_short"
        return result

    current_words = _normalize_words(current_text)
    result["relevant_words"] = len(current_words)

    if not current_words:
        result["reason"] = "no_relevant_words"
        return result

    best_document = None
    best_similarity = -1
    best_matching_words = -1
    best_dokid = -1

    for document in _load_documents():
        if exclude_document_id is not None and _as_int(document.get("DokID"), 0) == exclude_document_id:
            continue
        comparison_text = str(document.get("Doktext") or "")[:compare_length]
        similarity, matching_words, _ = _similarity(current_text, comparison_text)
        dokid = _as_int(document.get("DokID"), 0)

        # Zuerst höhere Ähnlichkeit, dann mehr gemeinsame Wörter,
        # bei Gleichstand das neuere Dokument mit höherer DokID.
        rank = (similarity, matching_words, dokid)
        best_rank = (best_similarity, best_matching_words, best_dokid)

        if rank > best_rank:
            best_document = document
            best_similarity = similarity
            best_matching_words = matching_words
            best_dokid = dokid

    if best_document is None or best_similarity < threshold:
        result["similarity"] = max(best_similarity, 0)
        result["matching_words"] = max(best_matching_words, 0)
        result["reason"] = "below_threshold"
        return result

    result.update({
        "matched": True,
        "similarity": best_similarity,
        "matching_words": best_matching_words,
        "source_document_id": best_document.get("DokID"),
        "category": str(best_document.get("kategorie_id") or ""),
        "category2": str(best_document.get("kategorie2_id") or ""),
        "document_type": str(best_document.get("doktyp_id") or ""),
        "partner": str(best_document.get("partner") or ""),
        "subject": str(best_document.get("betreff") or ""),
        "number": str(best_document.get("Nummer") or ""),
        "tags": str(best_document.get("tags") or ""),
        "reason": "matched",
    })

    return result
