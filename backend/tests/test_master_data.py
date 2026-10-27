import unittest
from unittest.mock import MagicMock, patch
from app.services.master_data import MasterDataError, check_usage, validate_name, definition, mutate, validate_explanation, list_entries


class MasterDataTests(unittest.TestCase):
    def test_inactive_rules_also_block_deactivation_and_delete(self):
        rules = [{"RegelID": "R0001", "Aktiv": 0}]
        for action in ("deactivate", "delete"):
            with self.assertRaises(MasterDataError) as error:
                check_usage(action, 0, rules)
            self.assertEqual(error.exception.rules, rules)

    def test_documents_allow_deactivation_but_block_delete(self):
        check_usage("deactivate", 4, [])
        with self.assertRaises(MasterDataError):
            check_usage("delete", 4, [])

    def test_unused_entries_can_be_deleted(self):
        check_usage("delete", 0, [])

    def test_rename_and_reactivate_allow_references(self):
        for action in ("rename", "activate"):
            check_usage(action, 2, [{"RegelID": "R0001"}])

    def test_validate_name(self):
        self.assertEqual(validate_name("  Versicherung  "), "Versicherung")
        for value in (" ", "a" * 256, "Name\nZeile"):
            with self.assertRaises(MasterDataError):
                validate_name(value)

    def test_optional_multiline_explanation_validation(self):
        self.assertEqual(validate_explanation("  Haus\nGebäudeversicherung  "), "Haus\nGebäudeversicherung")
        self.assertEqual(validate_explanation("  "), "")
        self.assertEqual(validate_explanation("x"*1000), "x"*1000)
        for value in ("x"*1001, "x\x00", "x\x7f", None):
            with self.assertRaises(MasterDataError):
                validate_explanation(value)

    def test_edit_explanation_is_bound_and_can_be_cleared(self):
        for kind, table, label in [('categories', 'kategorie', 'katBez'), ('document_types', 'doktyp', 'doktypBez')]:
            for explanation in ["O'Brien\nHaus und Garten", ""]:
                connection, cursor = self.fake_connection(2)
                cursor.fetchone.side_effect = [{"name": "test"}, {"acquired": 1}, {"id": "10"}, {"n": 2}, None, None]
                with patch("app.services.master_data.get_connection", return_value=connection):
                    mutate(kind, "test", "rename", "10", "Bezeichnung", explanation)
                cursor.execute.assert_any_call(f"UPDATE {table} SET {label} = %s, Erlaeuterung = %s WHERE id = %s", ("Bezeichnung", explanation, "10"))
                connection.commit.assert_called_once()

    def test_list_returns_explanations_and_usage(self):
        connection = MagicMock()
        cursor = connection.cursor.return_value
        cursor.fetchone.side_effect = [{"name": "test"}, {"n": 3}]
        cursor.fetchall.side_effect = [[{"id": "10", "name": "Haus", "active": 1, "explanation": "Gebäudeversicherung"}], []]
        with patch("app.services.master_data.get_connection", return_value=connection):
            result = list_entries("categories", "test")
        self.assertEqual(result['items'][0]['explanation'], 'Gebäudeversicherung')
        self.assertEqual(result['items'][0]['documents'], 3)
        cursor.execute.assert_any_call("SELECT id, katBez AS name, Aktiv AS active, COALESCE(Erlaeuterung, '') AS explanation FROM kategorie ORDER BY katBez, id")

    def test_only_fixed_table_names_accepted(self):
        self.assertEqual(definition("categories")[0], "kategorie")
        with self.assertRaises(MasterDataError):
            definition("kategorie; DROP TABLE dokumente")

    def fake_connection(self, usage):
        connection = MagicMock()
        cursor = connection.cursor.return_value
        cursor.fetchone.side_effect = [
            {"name": "test"}, {"acquired": 1}, {"id": "10"},
            {"n": usage}, None,
        ]
        cursor.fetchall.return_value = []
        return connection, cursor

    def test_deactivation_updates_only_master_row_and_commits(self):
        connection, cursor = self.fake_connection(3)
        with patch("app.services.master_data.get_connection", return_value=connection):
            mutate("categories", "test", "deactivate", "10")
        cursor.execute.assert_any_call("UPDATE kategorie SET Aktiv = %s WHERE id = %s", (0, "10"))
        connection.commit.assert_called_once()
        connection.close.assert_called_once()

    def test_delete_with_document_reference_rolls_back(self):
        connection, cursor = self.fake_connection(1)
        with patch("app.services.master_data.get_connection", return_value=connection):
            with self.assertRaises(MasterDataError):
                mutate("document_types", "test", "delete", "10")
        self.assertFalse(any(call.args[0].startswith("DELETE") for call in cursor.execute.call_args_list))
        connection.commit.assert_not_called()
        connection.rollback.assert_called()

    def test_rule_block_returns_links_data_without_update(self):
        connection, cursor = self.fake_connection(0)
        cursor.fetchall.return_value = [{"RegelID": "R0099", "Bezeichnung": "Regel", "Aktiv": 0}]
        with patch("app.services.master_data.get_connection", return_value=connection):
            with self.assertRaises(MasterDataError) as error:
                mutate("categories", "test", "deactivate", "10")
        self.assertEqual(error.exception.rules[0]["RegelID"], "R0099")
        connection.commit.assert_not_called()

    def test_database_mismatch_prevents_writes(self):
        connection, cursor = self.fake_connection(0)
        with patch("app.services.master_data.get_connection", return_value=connection):
            with self.assertRaises(MasterDataError):
                mutate("categories", "production", "delete", "10")
        connection.commit.assert_not_called()
        self.assertFalse(any(call.args[0].startswith("DELETE") for call in cursor.execute.call_args_list))


    def test_create_allocates_numeric_id_and_binds_name(self):
        connection = MagicMock()
        cursor = connection.cursor.return_value
        cursor.fetchone.side_effect = [{"name": "test"}, {"acquired": 1}, {"n": 0}, None, None]
        cursor.fetchall.side_effect = [[{"id": "10"}, {"id": "99"}], []]
        with patch("app.services.master_data.get_connection", return_value=connection):
            result = mutate("categories", "test", "create", name="Neue Kategorie")
        self.assertEqual(result["id"], "100")
        cursor.execute.assert_any_call(
            "INSERT INTO kategorie (id, katBez, Aktiv, Erlaeuterung) VALUES (%s, %s, 1, %s)",
            ("100", "Neue Kategorie", ""))
        connection.commit.assert_called_once()

    def test_rename_keeps_id_and_uses_bound_value(self):
        connection, cursor = self.fake_connection(2)
        cursor.fetchone.side_effect = [{"name": "test"}, {"acquired": 1}, {"id": "10"}, {"n": 2}, None, None]
        with patch("app.services.master_data.get_connection", return_value=connection):
            mutate("categories", "test", "rename", "10", "O'Brien")
        cursor.execute.assert_any_call(
            "UPDATE kategorie SET katBez = %s WHERE id = %s", ("O'Brien", "10"))
        connection.commit.assert_called_once()


if __name__ == "__main__":
    unittest.main()
