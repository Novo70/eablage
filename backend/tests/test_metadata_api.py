import json
import unittest

from fastapi import FastAPI
from fastapi.testclient import TestClient
from app.metadata_api import router
from app.services.metadata_service import REQUIRED_FIELDS


class MetadataApiTests(unittest.TestCase):
    def setUp(self):
        app = FastAPI()
        app.include_router(router)
        self.client = TestClient(app)
        self.addCleanup(self.client.close)
        self.document = dict.fromkeys(REQUIRED_FIELDS)
        self.document.update(DokID=10001,
                             Datum='2026-09-20', Archivierungsdatum='2026-09-20 12:34:56',
                             kategorie_id='001', katBez='Verträge',
                             Doktext='ÄÖ 😀\r\n"Text" ~te~', Aufbewahrungsdauer=0)

    def test_http_roundtrip_preserves_document_identity_and_text(self):
        response = self.client.post('/metadata/prepare', json={'document': self.document})
        self.assertEqual(response.status_code, 200)
        result = response.json()
        self.assertEqual(result['schema_version'], 3)
        self.assertEqual(result['filename'], '10001meta.json')
        content = json.loads(result['content'])
        self.assertEqual(content['document_id'], 10001)
        self.assertEqual(content['archived_at'], '2026-09-20T12:34:56')
        self.assertEqual(content['document_text'], self.document['Doktext'])
        self.assertEqual(content['category'], {'id': '001', 'label': 'Verträge'})
        self.assertEqual(content['retention_duration'], 0)
        self.assertIsNone(content['partner'])
        self.assertIsNone(content['original_filename'])
        self.assertIsNone(content['pdf_sha256'])
        self.assertIsNone(content['modified_at'])

    def test_missing_fields_invalid_dates_and_types_return_422(self):
        for document in [{}, {**self.document, 'Datum': '2026-02-30'},
                         {**self.document, 'DokID': '10001'},
                         {**self.document, 'DokID': True},
                         {**self.document, 'Doktext': ['text']}]:
            with self.subTest(document=document):
                response = self.client.post('/metadata/prepare', json={'document': document})
                self.assertEqual(response.status_code, 422)
                self.assertNotIn('content', response.json())

    def test_invalid_envelopes_are_rejected(self):
        for payload in [{}, {'document': None}, {'document': []},
                        {'document': self.document, 'write': True}]:
            with self.subTest(payload=payload):
                self.assertEqual(self.client.post('/metadata/prepare', json=payload).status_code, 422)
        self.assertEqual(self.client.post('/metadata/prepare', content='{',
                         headers={'Content-Type': 'application/json'}).status_code, 422)


if __name__ == '__main__':
    unittest.main()
