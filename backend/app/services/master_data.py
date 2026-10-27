"""Master data writes. IDs never change; all rule references block deactivation."""
import hashlib
import re
from app.db.database import get_connection

KINDS = {
    "number_types": ("nummernart", "Bezeichnung", (), ()),
    "categories": ("kategorie", "katBez", ("kategorie_id", "kategorie2_id"), ("kat", "kat2")),
    "document_types": ("doktyp", "doktypBez", ("doktyp_id",), ("dok",)),
}


class MasterDataError(ValueError):
    def __init__(self, message, rules=None):
        super().__init__(message)
        self.rules = rules or []


def definition(kind):
    if kind not in KINDS:
        raise MasterDataError("Unbekannte Stammdatenart.")
    return KINDS[kind]


def validate_name(name):
    name = name.strip()
    if not name or len(name) > 255 or any(ord(c) < 32 for c in name):
        raise MasterDataError("Bitte eine Bezeichnung mit 1 bis 255 Zeichen ohne Steuerzeichen eingeben.")
    return name


def validate_explanation(explanation):
    if not isinstance(explanation, str):
        raise MasterDataError("Bitte eine Erläuterung als Text eingeben.")
    explanation = explanation.strip()
    if len(explanation) > 1000 or any((ord(c) < 32 and c not in '\r\n\t') or ord(c) == 127 for c in explanation):
        raise MasterDataError("Die Erläuterung darf höchstens 1000 Zeichen und keine unzulässigen Steuerzeichen enthalten.")
    return explanation


def check_usage(action, documents, rules):
    if action in ("deactivate", "delete") and rules:
        raise MasterDataError("Zuerst die Verknüpfungen in den folgenden AutoKat-Regeln entfernen (auch inaktive Regeln).", rules)
    if action == "delete" and documents:
        raise MasterDataError("Löschen nicht möglich: Der Eintrag wird in Dokumenten verwendet. Bitte deaktivieren.")


def _database(cursor, database):
    cursor.execute("SELECT DATABASE() AS name")
    if cursor.fetchone()["name"] != database:
        raise MasterDataError("Webanwendung und Backend verwenden unterschiedliche Datenbanken.")


def _usage(cursor, definition, entry_id):
    table, _, doc_fields, rule_fields = definition
    if table == 'nummernart':
        cursor.execute('SELECT COUNT(*) AS n FROM dokument_nummer WHERE NummernartID=%s', (entry_id,))
        documents = int(cursor.fetchone()['n'])
        cursor.execute('SELECT a.RegelID,a.Bezeichnung,a.Aktiv FROM autokat a JOIN autokat_nummer n ON n.AutoKatID=a.id WHERE n.NummernartID=%s ORDER BY a.RegelID', (entry_id,))
        return documents, cursor.fetchall()
    cursor.execute("SELECT COUNT(*) AS n FROM dokumente WHERE " +
                   " OR ".join(f"{field} = %s" for field in doc_fields),
                   tuple(entry_id for _ in doc_fields))
    documents = int(cursor.fetchone()["n"])
    cursor.execute("SELECT RegelID, Bezeichnung, Aktiv FROM autokat WHERE " +
                   " OR ".join(f"{field} = %s" for field in rule_fields) + " ORDER BY RegelID",
                   tuple(entry_id for _ in rule_fields))
    return documents, cursor.fetchall()


def list_entries(kind, database):
    spec = definition(kind)
    table, label, _, _ = spec
    connection = get_connection()
    try:
        cursor = connection.cursor(dictionary=True)
        _database(cursor, database)
        order = 'Sortierung,' if kind == 'number_types' else ''
        extra = ',Sortierung AS sort_order' if kind == 'number_types' else ''
        cursor.execute(f"SELECT id, {label} AS name, Aktiv AS active, COALESCE(Erlaeuterung, '') AS explanation{extra} FROM {table} ORDER BY {order}{label}, id")
        items = cursor.fetchall()
        for item in items:
            item["documents"], item["rules"] = _usage(cursor, spec, item["id"])
        return {"items": items}
    finally:
        connection.close()


def mutate(kind, database, action, entry_id="", name="", explanation=None, sort_order=None):
    spec = definition(kind)
    table, label, _, _ = spec
    if action not in {"create", "rename", "deactivate", "activate", "delete"}:
        raise MasterDataError("Unbekannte Aktion.")
    if action in {"create", "rename"}:
        name = validate_name(name)
        if explanation is not None:
            explanation = validate_explanation(explanation)
    if action != "create" and (not entry_id or len(entry_id) > 20 or entry_id == "0"):
        raise MasterDataError("Ungültige ID.")
    connection = get_connection()
    lock = "eablage.master." + hashlib.sha256(database.encode()).hexdigest()[:40]
    locked = False
    try:
        cursor = connection.cursor(dictionary=True)
        _database(cursor, database)
        connection.rollback()
        cursor.execute("SELECT GET_LOCK(%s, 10) AS acquired", (lock,))
        locked = cursor.fetchone()["acquired"] == 1
        if not locked:
            raise MasterDataError("Stammdaten werden gerade geändert. Bitte erneut versuchen.")
        connection.rollback()
        connection.start_transaction(isolation_level="READ COMMITTED")
        if action == "create":
            # Existing AutoKat columns support three digits. New IDs remain numeric.
            cursor.execute(f"SELECT id FROM {table}")
            ids = [int(row["id"]) for row in cursor.fetchall() if re.fullmatch(r"[0-9]+", str(row["id"]))]
            entry_id = str(max([0, *ids]) + 1)
            if int(entry_id) > 999:
                raise MasterDataError("ID-Bereich bis 999 ausgeschöpft. Eine Schemaerweiterung ist erforderlich.")
        else:
            # PHP assignment writers hold shared locks on these rows until commit.
            cursor.execute(f"SELECT id FROM {table} WHERE id = %s FOR UPDATE", (entry_id,))
            if not cursor.fetchone():
                raise MasterDataError("Eintrag wurde nicht gefunden. Bitte Ansicht aktualisieren.")
        documents, rules = _usage(cursor, spec, entry_id)
        # Do not accidentally attach legacy orphaned references to a new entry.
        while action == "create" and (documents or rules):
            entry_id = str(int(entry_id) + 1)
            if int(entry_id) > 999:
                raise MasterDataError("ID-Bereich bis 999 ausgeschöpft.")
            documents, rules = _usage(cursor, spec, entry_id)
        check_usage(action, documents, rules)
        if action in {"create", "rename"}:
            cursor.execute(f"SELECT id FROM {table} WHERE {label} = %s AND id <> %s LIMIT 1", (name, entry_id))
            if cursor.fetchone():
                raise MasterDataError("Diese Bezeichnung existiert bereits, möglicherweise als inaktiver Eintrag.")
        if action == "create":
            cursor.execute(f"INSERT INTO {table} (id, {label}, Aktiv, Erlaeuterung) VALUES (%s, %s, 1, %s)", (entry_id, name, explanation or ""))
        elif action == "rename":
            if explanation is None:
                # Older callers which only rename must preserve the explanation.
                cursor.execute(f"UPDATE {table} SET {label} = %s WHERE id = %s", (name, entry_id))
            else:
                cursor.execute(f"UPDATE {table} SET {label} = %s, Erlaeuterung = %s WHERE id = %s", (name, explanation, entry_id))
        elif action == "delete":
            cursor.execute(f"DELETE FROM {table} WHERE id = %s", (entry_id,))
        else:
            cursor.execute(f"UPDATE {table} SET Aktiv = %s WHERE id = %s", (1 if action == "activate" else 0, entry_id))
        if kind == 'number_types' and action in {'create','rename'} and sort_order is not None:
            if type(sort_order) is not int or not 0 <= sort_order <= 9999:
                raise MasterDataError('Ungültige Sortierung.')
            cursor.execute('UPDATE nummernart SET Sortierung=%s WHERE id=%s',(sort_order,entry_id))
        connection.commit()
        return {"id": entry_id, "message": "Änderung gespeichert."}
    except Exception:
        connection.rollback()
        raise
    finally:
        if locked:
            cursor.execute("SELECT RELEASE_LOCK(%s)", (lock,))
            cursor.fetchone()
        connection.close()

