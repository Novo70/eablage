"""PHP client -> local HTTP -> actual FastAPI router; no archive or DB access."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'backend'))
from fastapi import FastAPI
from fastapi.testclient import TestClient
from app.metadata_api import router
from app.services.metadata_service import REQUIRED_FIELDS


@unittest.skipUnless(shutil.which('php'), 'PHP CLI required')
class PhpMetadataIntegration(unittest.TestCase):
    def setUp(self):
        app = FastAPI()
        app.include_router(router)
        self.client = TestClient(app)
        self.addCleanup(self.client.close)
        self.reply = None
        self.requests = 0
        case = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def do_POST(self):
                case.requests += 1
                payload = self.rfile.read(int(self.headers['Content-Length']))
                if case.reply is None:
                    result = case.client.post(self.path, content=payload,
                                              headers={'Content-Type': 'application/json'})
                    status, body = result.status_code, result.content
                else:
                    status, body = case.reply
                self.send_response(status)
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(body)

        self.server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.addCleanup(self.stop_server)
        self.url = f'http://127.0.0.1:{self.server.server_port}'
        self.document = dict.fromkeys(REQUIRED_FIELDS)
        self.document.update(DokID=42,
                             Datum='2026-09-20', Archivierungsdatum='2026-09-20 12:34:56',
                             kategorie_id='001', katBez='Verträge', Doktext='Ä 😀\n"Text"')
        self.document.update(Originaldateiname='Müller.PDF', DateiSHA256='a' * 64,
                             Aenderungsdatum='2026-09-21 14:34:56')

    def stop_server(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=2)

    def call_php(self, invalid_utf8=False):
        code = '''require $argv[1];
        try {
            $doc = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
            if ($argv[2] === 'bad') $doc['Doktext'] = "\\xFF";
            echo json_encode(eablage_metadaten_vorbereiten($doc, 1), JSON_THROW_ON_ERROR);
        } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(2); }'''
        return subprocess.run(['php', '-r', code, str(ROOT / 'web/metadaten_funktionen.php'),
                               'bad' if invalid_utf8 else 'ok'],
                              input=json.dumps(self.document), text=True, capture_output=True,
                              env={**os.environ, 'BACKEND_URL': self.url}, timeout=5)

    def test_real_router_roundtrip(self):
        result = self.call_php()
        self.assertEqual(result.returncode, 0, result.stderr)
        envelope = json.loads(result.stdout)
        metadata = json.loads(envelope['content'])
        self.assertEqual(envelope['filename'], '42meta.json')
        self.assertEqual(metadata['document_text'], self.document['Doktext'])
        self.assertEqual(metadata['category']['id'], '001')
        self.assertEqual(metadata['archived_at'], '2026-09-20T12:34:56')
        self.assertEqual(metadata['schema_version'], 4)
        self.assertEqual(metadata['original_filename'], 'Müller.PDF')
        self.assertEqual(metadata['pdf_sha256'], 'a' * 64)
        self.assertEqual(metadata['modified_at'], '2026-09-21T14:34:56')

    def test_validation_error_is_not_returned_as_content(self):
        del self.document['Datum']
        result = self.call_php()
        self.assertEqual(result.returncode, 2)
        self.assertIn('422', result.stderr)
        self.assertEqual(result.stdout, '')

    def test_invalid_utf8_fails_before_request(self):
        self.assertEqual(self.call_php(invalid_utf8=True).returncode, 2)
        self.assertEqual(self.requests, 0)

    def test_bad_responses_fail_closed(self):
        for status, body in [(500, b'{}'), (200, b'not-json'),
                             (200, json.dumps({'schema_version': 3, 'filename': '../42meta.json',
                                               'content': '{}'}).encode()),
                             (200, json.dumps({'schema_version': 3, 'filename': '42meta.json',
                                               'content': '{"schema_version":3,"document_id":43}'}).encode()),
                             (200, json.dumps({'schema_version': 1, 'filename': '42meta.json',
                                               'content': '{"schema_version":1,"document_id":42}'}).encode())]:
            with self.subTest(status=status, body=body):
                self.reply = status, body
                self.assertEqual(self.call_php().returncode, 2)

    def test_unavailable_backend_fails(self):
        # The server socket remains bound but no HTTP serving occurs: timeout.
        self.server.shutdown()
        result = self.call_php()
        self.assertEqual(result.returncode, 2)
        self.assertIn('Zeitlimit', result.stderr)


if __name__ == '__main__':
    unittest.main()

