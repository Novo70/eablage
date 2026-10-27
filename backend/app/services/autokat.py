import re
from typing import Optional

from app.db.database import get_connection
from app.services.numbers import extract_number
from app.services.date_parser import find_document_date
from app.services.text_extractor import extract_text_after


RESULT_FIELDS = (
    "date",
    "category",
    "category2",
    "document_type",
    "partner",
    "subject",
    "number",
    "tags",
)

LOOKUP_FIELDS = {"category", "category2", "document_type"}


def load_autokat_rules() -> list[dict]:
    """Load active AutoKat rules in their configured rank order."""
    connection = get_connection()
    try:
        cursor = connection.cursor(dictionary=True)
        cursor.execute(
            """
            SELECT *
            FROM autokat
            WHERE COALESCE(Aktiv, 1) = 1
            ORDER BY Rang ASC, id ASC
            """
        )
        rules = cursor.fetchall()
        cursor.execute("SELECT n.* FROM autokat_nummer n JOIN nummernart a ON a.id=n.NummernartID WHERE a.Aktiv=1 ORDER BY n.Sortierung,n.ID")
        definitions = cursor.fetchall()
        for rule in rules:
            rule['number_definitions'] = [d for d in definitions if d['AutoKatID'] == rule['id']]
        return rules
    finally:
        connection.close()


def _rule_type(rule: dict) -> str:
    """Return the configured rule type E (supplement) or K (classification)."""
    rule_type = str(rule.get("Regeltyp") or "").strip().upper()
    return rule_type if rule_type in {"E", "K"} else ""


def _contains_whole_term(text: str, search_term: str) -> bool:
    """Match a configured search term only as a complete word or phrase.

    This prevents e.g. 'Rechnung' from matching inside
    'Verbrauchsabrechnung', while punctuation around the term remains valid.
    Multi-word phrases are treated as one configured search expression.
    """
    search_term = str(search_term or "").strip()
    if not search_term:
        return False

    pattern = r"(?<!\w)" + re.escape(search_term) + r"(?!\w)"
    return re.search(pattern, text, flags=re.IGNORECASE | re.UNICODE) is not None


def _split_search_terms(value) -> list[str]:
    """Split semicolon-separated search terms and ignore empty entries."""
    return [
        term.strip()
        for term in str(value or "").split(";")
        if term.strip()
    ]


def _text_matches_rule(text: str, rule: dict) -> bool:
    """Check positive and exclusion terms of one AutoKat rule.

    All configured positive search terms must occur in the document text.
    If at least one exclusion term occurs, the rule does not match.
    """
    search_terms = _split_search_terms(rule.get("Suchbegriffe"))
    exclusion_terms = _split_search_terms(rule.get("Ausschlussbegriffe"))

    if not search_terms:
        return False

    if not all(_contains_whole_term(text, term) for term in search_terms):
        return False

    if any(_contains_whole_term(text, term) for term in exclusion_terms):
        return False

    return True


def find_matching_rule(text: str) -> Optional[dict]:
    """Return the first matching classification rule, otherwise supplement rule."""
    if not text:
        return None

    rules = load_autokat_rules()
    for wanted_type in ("K", "E"):
        for rule in rules:
            if _rule_type(rule) == wanted_type and _text_matches_rule(text, rule):
                return rule
    return None


def _safe_int(value, default: int = 0) -> int:
    try:
        return int(value or 0)
    except (TypeError, ValueError):
        return default


def _rule_reference(rule: dict, rule_type: str) -> dict:
    return {
        "type": rule_type,
        "regel_id": str(rule.get("RegelID") or "").strip(),
        "version": _safe_int(rule.get("Version"), 1),
        "rank": rule.get("Rang"),
        "rule_id": rule.get("id"),
    }


def _rule_source(rule: dict, rule_type: str, method: str = "fixed") -> dict:
    source = _rule_reference(rule, rule_type)
    source.update(
        {
            "label": str(rule.get("Bezeichnung") or "").strip(),
            "method": method,
        }
    )
    return source


def _set_field(
    result: dict,
    field: str,
    value,
    rule: dict,
    rule_type: str,
    method: str = "fixed",
    allow_default_date_override: bool = False,
) -> bool:
    """Apply a rule value according to the AutoKat precedence rules.

    Empty rule values never clear an existing result. For category and document
    type fields, the value '0' is treated as empty/unassigned. Supplement rules
    (E) fill empty fields and replace values from earlier supplement rules.
    Anchored date extraction may also replace the generic date parser result.
    The primary classification rule (K) may overwrite supplement values.
    """
    if value is None:
        return False

    normalized = str(value).strip()
    if not normalized or (field in LOOKUP_FIELDS and normalized == "0"):
        return False

    if rule_type == "E" and result.get(field):
        source_type = (result.get("sources", {}).get(field) or {}).get("type")
        if not (
            source_type == "E"
            or (field == "date" and allow_default_date_override and source_type == "D")
        ):
            return False

    result[field] = value if field == "date" else normalized
    result["sources"][field] = _rule_source(rule, rule_type, method)
    return True


def _extract_rule_values(text: str, rule: dict) -> dict:
    """Build the values proposed by one rule before applying precedence rules."""
    values = {
        "category": (str(rule.get("kat") or "").strip(), "fixed"),
        "category2": (str(rule.get("kat2") or "").strip(), "fixed"),
        "document_type": (str(rule.get("dok") or "").strip(), "fixed"),
        "partner": (str(rule.get("partner") or "").strip(), "fixed"),
        "subject": (str(rule.get("betreff") or "").strip(), "fixed"),
        "number": (str(rule.get("nr") or "").strip(), "fixed"),
        "tags": (str(rule.get("tags") or "").strip(), "fixed"),
    }

    pr_betreff = str(rule.get("prBetreff") or "").strip()
    if pr_betreff:
        extracted_subject = extract_text_after(
            text=text,
            search_text=pr_betreff,
            prefix=str(rule.get("prBetreffAnz") or ""),
            length=_safe_int(rule.get("BetreffLänge")),
            end_text=str(rule.get("BetreffEnde") or "").strip(),
        )
        if str(extracted_subject or "").strip():
            values["subject"] = (str(extracted_subject).strip(), "extract")

    pr_num = str(rule.get("prNum") or "").strip()
    if pr_num:
        extracted_number = extract_text_after(
            text=text,
            search_text=pr_num,
            prefix=str(rule.get("prNumAnz") or ""),
            length=_safe_int(rule.get("NumLänge")),
            end_text=str(rule.get("NumEnde") or "").strip(),
        )
        if str(extracted_number or "").strip():
            values["number"] = (str(extracted_number).strip(), "extract")

    return values


def _apply_rule(result: dict, text: str, rule: dict, rule_type: str) -> None:
    values = _extract_rule_values(text, rule)
    for definition in rule.get('number_definitions', []):
        kind = str(definition['NummernartID'])
        if any(n['kind_id'] == kind for n in result['numbers']):
            continue
        value = extract_number(text, definition)
        if value:
            result['numbers'].append({'kind_id': kind, 'value': value,
                'position': len(result['numbers']) * 10 + 10,
                'source': _rule_source(rule, rule_type, 'number_anchor' if definition.get('Startanker') else 'fixed')})

    for field, (value, method) in values.items():
        _set_field(
            result=result,
            field=field,
            value=value,
            rule=rule,
            rule_type=rule_type,
            method=method,
        )

    pr_datum = str(rule.get("prDatum") or "").strip()
    if pr_datum:
        found_date = find_document_date(text=text, search_text=pr_datum)
        if found_date:
            _set_field(
                result=result,
                field="date",
                value=found_date,
                rule=rule,
                rule_type=rule_type,
                method="date_anchor",
                allow_default_date_override=True,
            )


def analyze_autokat(text: str) -> dict:
    result = {
        "date": None,
        "category": "",
        "category2": "",
        "document_type": "",
        "partner": "",
        "subject": "",
        "number": "",
        "numbers": [],
        "tags": "",
        "matched": False,
        "matched_rules": 0,
        "matched_ranks": [],
        "matched_supplement_rules": [],
        "matched_classification_rule": None,
        "matched_classification_rules": [],
        "matched_supplement_rule_refs": [],
        "matched_classification_rule_ref": None,
        "matched_classification_rule_refs": [],
        "multiple_classification_matches": False,
        "stopped_by_rule": False,
        "sources": {field: None for field in RESULT_FIELDS},
        "mixed_rule_sources": False,
    }

    if not text:
        return result

    default_date = find_document_date(text=text)
    if default_date:
        result["date"] = default_date
        result["sources"]["date"] = {
            "type": "D",
            "regel_id": "",
            "version": None,
            "rank": None,
            "rule_id": None,
            "label": "Allgemeine Datumserkennung",
            "method": "default_date",
        }

    rules = load_autokat_rules()
    supplement_rules = [rule for rule in rules if _rule_type(rule) == "E"]
    classification_rules = [rule for rule in rules if _rule_type(rule) == "K"]

    # A matching classification rule excludes all supplements for numbers.
    number_classification = next((r for r in classification_rules if _text_matches_rule(text, r)), None)
    # Phase 1: matching supplement rules refine values in rank/id order.
    # Later nonempty values overwrite earlier supplement values; empty ones retain them.
    for rule in supplement_rules:
        if not _text_matches_rule(text, rule):
            continue

        result["matched"] = True
        result["matched_rules"] += 1
        result["matched_ranks"].append(rule.get("Rang"))
        result["matched_supplement_rules"].append(rule.get("Rang"))
        result["matched_supplement_rule_refs"].append(_rule_reference(rule, "E"))
        saved_numbers = list(result['numbers'])
        _apply_rule(result, text, rule, "E")
        if number_classification:
            result['numbers'] = saved_numbers

    # Phase 2: inspect all matching classification rules. The first one in rank
    # order is the primary rule and supplies the classification values. Further
    # matches are reported for review but never overwrite the primary result.
    primary_classification_applied = False
    for rule in classification_rules:
        if not _text_matches_rule(text, rule):
            continue

        result["matched"] = True
        result["matched_rules"] += 1
        result["matched_ranks"].append(rule.get("Rang"))
        result["matched_classification_rules"].append(rule.get("Rang"))
        rule_ref = _rule_reference(rule, "K")
        result["matched_classification_rule_refs"].append(rule_ref)

        if not primary_classification_applied:
            result["matched_classification_rule"] = rule.get("Rang")
            result["matched_classification_rule_ref"] = rule_ref
            _apply_rule(result, text, rule, "K")
            primary_classification_applied = True

    if any('number_definitions' in r for r in rules):
        result['number'] = result['numbers'][0]['value'] if result['numbers'] else ''
        result['sources']['number'] = result['numbers'][0]['source'] if result['numbers'] else None
    result["multiple_classification_matches"] = len(result["matched_classification_rule_refs"]) > 1

    source_types = {
        source.get("type")
        for source in result["sources"].values()
        if isinstance(source, dict) and source.get("type") in {"E", "K"}
    }
    result["mixed_rule_sources"] = source_types == {"E", "K"}

    return result

