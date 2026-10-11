import re


def find_search_position(text: str, search_text: str) -> tuple[int, int] | None:
    """
    Sucht einen AutoKat-Suchbegriff als eigenständigen Begriff bzw. Phrase.

    Beispiele:
    - "Datum" findet "Datum: 01.02.2026"
    - "Datum" findet NICHT "Geburtsdatum"
    - "Rechnung Nr." kann auch über Zeilenumbrüche / mehrere Leerzeichen
      hinweg gefunden werden.

    Rückgabe: (Startposition, Endposition) des Treffers oder None.
    """
    if not text or not search_text:
        return None

    search_text = search_text.strip()
    if not search_text:
        return None

    parts = re.split(r"\s+", search_text)
    pattern_text = r"\s+".join(re.escape(part) for part in parts if part)

    if not pattern_text:
        return None

    if re.match(r"\w", search_text[0], flags=re.UNICODE):
        pattern_text = r"(?<!\w)" + pattern_text

    if re.match(r"\w", search_text[-1], flags=re.UNICODE):
        pattern_text = pattern_text + r"(?!\w)"

    match = re.search(pattern_text, text, flags=re.IGNORECASE | re.UNICODE)

    if match is None:
        return None

    return match.start(), match.end()


def extract_text_after(
    text: str,
    search_text: str,
    prefix: str = "",
    length: int = 0,
    end_text: str = "",
) -> str:
    """
    Extrahiert Text nach einem AutoKat-Präfix.

    - search_text markiert den Beginn des zu übernehmenden Texts.
    - end_text ist optional und markiert dessen Ende.
    - length bleibt die maximale Länge bzw. der Fallback, wenn kein
      end_text gesetzt oder gefunden wird.
    - end_text wird ausschließlich NACH search_text gesucht.
    - prefix wird vorangestellt.

    Sonderfall length == 99:
    Bei einer Leerzeile wird die Übernahme beendet.
    """

    match_position = find_search_position(text, search_text)

    if match_position is None:
        return ""

    _, start = match_position
    remaining = text[start:]

    # Die bisherige Länge bleibt als maximale Begrenzung bestehen.
    max_end = len(remaining)
    if length > 0:
        max_end = min(length, len(remaining))

    # Optionaler Endmarker: nur im Text NACH dem Präfix suchen.
    end_text = end_text.strip()
    if end_text:
        end_position = find_search_position(remaining, end_text)
        if end_position is not None:
            end_start, _ = end_position
            max_end = min(max_end, end_start)

    value = remaining[:max_end]

    # Bestehender Sonderfall: Ende beim nächsten Absatz / bei Leerzeile.
    if length == 99:
        paragraph_breaks = [
            value.find("\r\n\r\n"),
            value.find("\n\n"),
        ]

        paragraph_breaks = [
            pos for pos in paragraph_breaks if pos >= 0
        ]

        if paragraph_breaks:
            value = value[:min(paragraph_breaks)]

    value = value.replace("\r", "")
    value = value.replace("\n", "")

    value = prefix + value

    value = value.replace("\\", "-")
    value = value.replace("/", "-")

    value = re.sub(r" {2,}", " ", value)
    value = value.strip()

    if value.startswith(":"):
        value = value[1:].strip()

    return value
