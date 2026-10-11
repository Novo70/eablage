"""Explicit OCR bypasses prepared proposals without changing normal worker behavior."""
from contextlib import nullcontext
import json
from pathlib import Path
import unittest
from unittest.mock import MagicMock, patch

from fastapi import FastAPI
from fastapi.testclient import TestClient
from app.inbox_api import router
from app.services import inbox


class InboxOCRTest(unittest.TestCase):
    def run_job(self, requested=False, status='bereit', acquired=True, pdf_ok=True):
        raw, digest = b'%PDF source bytes', 'a' * 64
        previous = dict(id=7, sha256=digest, status=status, fehler='', dok_id=42 if status == 'archiviert' else None,
                        ergebnis=json.dumps({'pdf': {'ok': True, 'text': 'Wrong old text'}, 'classification': {}}))
        connection = MagicMock()
        cursor = connection.cursor.return_value
        cursor.fetchone.side_effect = [(1,), previous, (1,)]
        pdf = dict(ok=pdf_ok, text='Fresh OCR text' if pdf_ok else '', used_mode='ocr',
                   error='' if pdf_ok else 'OCR ist deaktiviert.')
        with patch.object(inbox, 'get_connection', return_value=connection), \
             patch.object(inbox, 'settings', return_value={'PDFTextMode': 'layout'}), \
             patch.object(inbox, 'file_path', return_value=Path('source.pdf')), \
             patch.object(inbox, 'file_lock', return_value=nullcontext(acquired)), \
             patch.object(inbox, 'read_pdf', return_value=(raw, digest)), \
             patch.object(inbox, 'extract', return_value=pdf) as extract, \
             patch.object(inbox, 'classify', side_effect=lambda text: {'partner': text}) as classify:
            result = inbox.process('testdb', 'source.pdf', ocr_requested=requested)
        connection.close.assert_called_once()
        return result, extract, classify, raw

    def test_normal_open_keeps_cached_result(self):
        result, extract, classify, _ = self.run_job()
        self.assertEqual(result['result']['pdf']['text'], 'Wrong old text')
        extract.assert_not_called()
        classify.assert_not_called()

    def test_explicit_ocr_bypasses_ready_cache_and_reclassifies(self):
        result, extract, classify, raw = self.run_job(requested=True)
        self.assertEqual(result['status'], 'bereit')
        self.assertEqual(result['id'], 7)
        self.assertEqual(result['sha256'], 'a' * 64)
        self.assertEqual(result['result']['classification']['partner'], 'Fresh OCR text')
        extract.assert_called_once_with(raw, 'layout', ocr_requested=True)
        classify.assert_called_once_with('Fresh OCR text')

    def test_explicit_ocr_retries_failed_job(self):
        result, extract, _, _ = self.run_job(requested=True, status='fehler')
        self.assertEqual(result['status'], 'bereit')
        self.assertTrue(extract.call_args.kwargs['ocr_requested'])

    def test_archived_job_is_not_reopened_by_ocr(self):
        result, extract, _, _ = self.run_job(requested=True, status='archiviert')
        self.assertEqual(result['status'], 'archiviert')
        extract.assert_not_called()

    def test_busy_file_does_not_run_ocr_in_parallel(self):
        result, extract, _, _ = self.run_job(requested=True, acquired=False)
        self.assertEqual(result['status'], 'verarbeitung')
        extract.assert_not_called()

    def test_ocr_failure_returns_no_old_text_as_new_result(self):
        result, _, classify, _ = self.run_job(requested=True, pdf_ok=False)
        self.assertEqual(result['status'], 'fehler')
        self.assertIn('deaktiviert', result['error'])
        self.assertEqual(result['result']['pdf']['text'], '')
        classify.assert_called_once_with('')

    def test_api_forwards_ocr_flag_and_preserves_default(self):
        app = FastAPI()
        app.include_router(router)
        with TestClient(app) as client, patch.object(inbox, 'process', return_value={'status': 'bereit'}) as process:
            for requested in [False, True]:
                payload = {'database': 'testdb', 'filename': 'source.pdf'}
                if requested:
                    payload['ocr_requested'] = True
                response = client.post('/inbox/process', json=payload)
                self.assertEqual(response.status_code, 200, response.text)
                process.assert_called_with('testdb', 'source.pdf', False, requested)
