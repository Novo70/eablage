from copy import deepcopy
from datetime import date, datetime
import unittest
from unittest.mock import patch

from app.services import export_service as export


class ExportStructureTests(unittest.TestCase):
    def setUp(self):
        self.document = {
            'DokID': 10001, 'Datum': date(2026, 9, 20), 'katBez': 'Versicherung',
            'kat2Bez': 'Haus', 'doktypBez': 'Rechnung', 'Partner': 'Müller & Söhne',
        }
        self.settings = {'expVerzeichnisstruktur': 'ja', 'expPartner': 'ja'}

    def levels(self, *names):
        return {**self.settings, **{f'expVerzeichnisEbene{i}': name
                                   for i, name in enumerate(names, 1)}}

    def test_existing_four_levels_and_missing_fifth_are_unchanged(self):
        self.assertEqual(export.build_export_directories(self.document, self.levels(
            'Kategorie', 'Kategorie2', 'Dokumententyp', 'Partner')),
            ['Versicherung', 'Haus', 'Rechnung', 'Müller & Söhne'])

    def test_five_levels_in_configured_order_and_sixth_ignored(self):
        settings = self.levels('Kategorie', 'Jahr', 'Monat', 'Dokumententyp', 'Partner', 'Kategorie2')
        self.assertEqual(export.MAX_EXPORT_DIRECTORY_LEVELS, 5)
        self.assertEqual(export.build_export_directories(self.document, settings),
                         ['Versicherung', '2026', '09 - September', 'Rechnung', 'Müller & Söhne'])

    def test_empty_features_and_disabled_levels_are_skipped(self):
        document = {**self.document, 'kat2Bez': None, 'Partner': '  '}
        self.assertEqual(export.build_export_directories(document, self.levels(
            'Kategorie2', '(keine)', 'Jahr', 'Partner', 'Monat')), ['2026', '09 - September'])
        self.assertEqual(export.build_export_directories(document, self.levels(
            '', 'nein', 'unknown', 'Kategorie', '(keine)')), ['Versicherung'])

    def test_number_level_groups_documents_by_stored_number(self):
        settings = self.levels('Kategorie', 'Nummer')
        document = {**self.document, 'Nummer': '12345'}
        self.assertEqual(export.build_export_directories(document, settings),
                         ['Versicherung', '12345'])
        self.assertEqual(export.build_export_directories(
            {**document, 'Nummer': 'Konto 12345'}, settings),
            ['Versicherung', 'Konto 12345'])
        self.assertEqual(export.build_export_directories(
            {**document, 'Nummer': '  '}, settings), ['Versicherung'])
        self.assertEqual(export.build_export_directories(
            {**document, 'Nummer': 'Konto 12/345: West'}, settings),
            ['Versicherung', 'Konto 12-345- West'])

    def test_disabled_structure_is_flat(self):
        settings = {**self.levels('Jahr', 'Monat', 'Kategorie', 'Partner', 'Dokumententyp'),
                    'expVerzeichnisstruktur': 'nein'}
        self.assertEqual(export.build_export_directories(self.document, settings), [])

    def test_all_german_months_sort_chronologically(self):
        expected = ['01 - Januar', '02 - Februar', '03 - März', '04 - April',
                    '05 - Mai', '06 - Juni', '07 - Juli', '08 - August',
                    '09 - September', '10 - Oktober', '11 - November', '12 - Dezember']
        actual = [export.build_export_directories(
            {**self.document, 'Datum': date(2024, month, 1)}, self.levels('Monat'))[0]
            for month in range(1, 13)]
        self.assertEqual(actual, expected)
        self.assertEqual(sorted(actual), expected)

    def test_database_and_iso_dates_and_leap_day(self):
        for value in (date(2024, 2, 29), datetime(2024, 2, 29, 23, 59), '2024-02-29'):
            with self.subTest(value=value):
                self.assertEqual(export.build_export_directories(
                    {**self.document, 'Datum': value}, self.levels('Jahr', 'Monat')),
                    ['2024', '02 - Februar'])

    def test_invalid_or_missing_date_never_invents_year_or_month(self):
        for value in (None, '', '0000-00-00', '2026-02-29', '2026-13-01',
                      '20260920', '20.09.2026', '2026-09', 'bad', 2026):
            with self.subTest(value=value):
                self.assertEqual(export.build_export_directories(
                    {**self.document, 'Datum': value}, self.levels('Jahr', 'Kategorie', 'Monat')),
                    ['Versicherung'])

    def test_existing_path_cleaning_applies_to_fifth_level(self):
        document = {**self.document, 'Partner': '../Müller / Haus: West'}
        self.assertEqual(export.build_export_directories(document, self.levels(
            '(keine)', '(keine)', '(keine)', '(keine)', 'Partner')), ['Müller - Haus- West'])

    def test_complete_plan_resolves_collisions_stably_within_directories(self):
        documents = [self.document, {**self.document, 'DokID': 10002},
                     {**self.document, 'DokID': 10003, 'Datum': date(2025, 9, 20)}]
        settings = self.levels('Jahr', 'Monat')
        before = deepcopy((documents, settings))
        plan = export.build_full_export_plan(documents, settings)
        self.assertEqual(plan, export.build_full_export_plan(list(reversed(documents)), settings))
        items = plan['items']
        self.assertEqual(items[0]['filename'], items[2]['filename'])
        self.assertNotEqual(items[0]['filename'], items[1]['filename'])
        self.assertIn('DokID 10002', items[1]['filename'])
        self.assertEqual((documents, settings), before)

    def test_plan_provides_display_metadata_without_changing_export_name(self):
        document = {**self.document, 'Betreff': 'Monatsauszug', 'Nummer': '4711'}
        item = export.build_full_export_plan([document], self.settings)['items'][0]
        self.assertEqual(item['display'], {
            'date': date(2026, 9, 20), 'category': 'Versicherung',
            'type': 'Rechnung', 'partner': 'Müller & Söhne',
            'subject': 'Monatsauszug', 'number': '4711', 'numbers': [],
        })
        self.assertTrue(item['filename'].endswith('.pdf'))

    def test_full_export_uses_current_profile_and_shared_plan(self):
        with patch.object(export, 'load_documents', return_value=[self.document]), \
                patch.object(export, 'load_export_settings', return_value=self.levels('Jahr')) as load:
            first = export.prepare_full_export()
            load.assert_called_once_with(export.PROFILE_FULL)
            self.assertEqual(first['items'][0]['directories'], ['2026'])
            load.return_value = self.levels('Monat')
            second = export.prepare_full_export()
            self.assertEqual(second['items'][0]['directories'], ['09 - September'])
            self.assertEqual(second['items'], export.build_full_export_plan(
                [self.document], load.return_value)['items'])

    def test_empty_database_and_single_export_profile(self):
        self.assertEqual(export.build_full_export_plan([], self.settings)['items'], [])
        with patch.object(export, 'load_document', return_value=self.document), \
                patch.object(export, 'load_export_settings', return_value={'expDokID': 'ja'}) as load:
            result = export.prepare_single_export(10001)
            load.assert_called_once_with(export.PROFILE_SINGLE)
            self.assertEqual(result, {'dok_id': 10001, 'filename': '10001.pdf', 'profile': 2, 'numbers': []})

    def test_original_mode_and_legacy_fallback(self):
        doc = {**self.document, 'Originaldateiname': 'Konto Müller 09.PDF'}
        settings = {**self.settings, 'expDateinameModus': 'original'}
        self.assertEqual(export.build_export_filename(doc, settings), 'Konto Müller 09.pdf')
        generated = export.build_export_filename(doc, self.settings)
        self.assertEqual(generated, 'Müller & Söhne.pdf')
        for missing in (None, ''):
            self.assertEqual(export.build_export_filename({**doc, 'Originaldateiname': missing}, settings), generated)
        with patch.object(export, 'load_document', return_value=doc), \
                patch.object(export, 'load_export_settings', return_value=settings):
            self.assertEqual(export.prepare_single_export(doc['DokID'])['filename'], 'Konto Müller 09.pdf')

    def test_original_names_are_portable_bounded_and_collision_safe(self):
        settings = {**self.settings, 'expDateinameModus': 'original'}
        name = 'ä' * 240 + '.pdf'
        docs = [{**self.document, 'DokID': i, 'Originaldateiname': name} for i in (1, 2, 3)]
        plan = export.build_full_export_plan(docs, settings)
        names = [item['filename'] for item in plan['items']]
        self.assertEqual(len(set(names)), 3)
        self.assertTrue(all(len(name.encode()) <= 255 for name in names))
        self.assertEqual(plan, export.build_full_export_plan(list(reversed(docs)), settings))
        cleaned = export.build_export_filename({**self.document, 'Originaldateiname': 'Konto: 09?.pdf'}, settings)
        self.assertEqual(cleaned, 'Konto- 09.pdf')
        for incoming in ('CON.pdf', 'COM1.pdf', 'NUL.txt.pdf'):
            with self.subTest(incoming=incoming):
                self.assertTrue(export.build_export_filename({**self.document, 'Originaldateiname': incoming}, settings).startswith('_'))
        self.assertEqual(export.build_export_filename(
            {**self.document, 'Originaldateiname': 'CONtemporary.pdf'},
            {**settings, 'expMaxLaengeDateiname': '3'}), '_CON.pdf')

    def test_invalid_mode_and_original_path_are_rejected(self):
        with self.assertRaises(ValueError):
            export.build_export_filename(self.document, {'expDateinameModus': 'typo'})
        with self.assertRaises(ValueError):
            export.build_export_filename({**self.document, 'Originaldateiname': '../escape.pdf'},
                                         {'expDateinameModus': 'original'})

    def test_api_reports_invalid_export_data_without_producing_a_plan(self):
        from fastapi.testclient import TestClient
        import main
        with TestClient(main.app) as client:
            for path, function in [('/export/plan', 'prepare_full_export'),
                                   ('/export/document/10001', 'prepare_single_export')]:
                with self.subTest(path=path), patch.object(main, function, side_effect=ValueError('Ungültiger Modus')):
                    response = client.get(path)
                    self.assertEqual(response.status_code, 400)
                    self.assertEqual(response.json()['detail'], 'Ungültiger Modus')

    def test_original_and_generated_fallback_collide_safely(self):
        docs = [{**self.document, 'DokID': 1, 'Originaldateiname': 'Müller & Söhne.pdf'},
                {**self.document, 'DokID': 2},
                {**self.document, 'DokID': 3, 'Originaldateiname': 'müller & söhne.pdf'}]
        items = export.build_full_export_plan(docs, {**self.settings, 'expDateinameModus': 'original'})['items']
        self.assertEqual(len({item['filename'].casefold() for item in items}), 3)

