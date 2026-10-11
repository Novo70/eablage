import re
from datetime import date
from typing import Optional

from app.services.text_extractor import find_search_position


MONTHS = {
    "Januar": 1,
    "Februar": 2,
    "März": 3,
    "April": 4,
    "Mai": 5,
    "Juni": 6,
    "Juli": 7,
    "August": 8,
    "September": 9,
    "Oktober": 10,
    "November": 11,
    "Dezember": 12,
}


def _normalize_year(year_text: str) -> int:
    """Wandelt zwei- oder vierstellige Jahreszahlen in ein volles Jahr um."""
    year = int(year_text)

    if len(year_text) == 2:
        # 00-69 -> 2000-2069, 70-99 -> 1970-1999
        return 2000 + year if year <= 69 else 1900 + year

    return year


def _valid_date(year: int, month: int, day: int) -> Optional[str]:
    """Prüft ein Datum und liefert YYYY-MM-DD zurück."""
    try:
        value = date(year, month, day)
        return value.isoformat()
    except ValueError:
        return None


def find_document_date(text: str, search_text: str = "") -> Optional[str]:
    """
    Sucht das erste plausible Datum im Dokumenttext.

    Unterstützte Formate:
    - 19.08.2026
    - 19.08.26
    - 19. August 2026
    - August 2026

    Zweistellige Jahre werden so interpretiert:
    - 00-69 -> 2000-2069
    - 70-99 -> 1970-1999

    Wenn search_text angegeben ist, beginnt die Suche nach dessen
    erstem exakten Auftreten als eigenständiger Begriff bzw. Phrase.
    """

    if not text:
        return None

    if search_text:
        match_position = find_search_position(text, search_text)
        if match_position is None:
            return None

        _, search_end = match_position
        text = text[search_end:]

    candidates = []

    pattern_short = re.compile(
        r"\b(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})\b"
    )

    for match in pattern_short.finditer(text):
        day = int(match.group(1))
        month = int(match.group(2))
        year = _normalize_year(match.group(3))

        value = _valid_date(year, month, day)

        if value:
            candidates.append((match.start(), value))

    month_names = "|".join(MONTHS.keys())

    pattern_long = re.compile(
        rf"\b(\d{{1,2}})\.\s+({month_names})\s+(\d{{4}})\b",
        re.IGNORECASE,
    )

    for match in pattern_long.finditer(text):
        day = int(match.group(1))
        month_name = match.group(2)

        month = next(
            number
            for name, number in MONTHS.items()
            if name.lower() == month_name.lower()
        )

        year = int(match.group(3))

        value = _valid_date(year, month, day)

        if value:
            candidates.append((match.start(), value))

    pattern_month = re.compile(
        rf"\b({month_names})\s+(\d{{4}})\b",
        re.IGNORECASE,
    )

    for match in pattern_month.finditer(text):
        if re.search(r"\b\d{1,2}\.\s*$", text[:match.start()]):
            continue
        month_name = match.group(1)

        month = next(
            number
            for name, number in MONTHS.items()
            if name.lower() == month_name.lower()
        )

        year = int(match.group(2))

        value = _valid_date(year, month, 1)

        if value:
            candidates.append((match.start(), value))

    if not candidates:
        return None

    candidates.sort(key=lambda item: item[0])

    return candidates[0][1]
