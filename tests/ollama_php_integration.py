"""Exercise the PHP bridge, CSRF and payload boundaries without a database."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN', shutil.which('php'))


@unittest.skipUnless(PHP, 'PHP CLI required')
class OllamaBridgeTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        for filename in ('ollama.php', 'csrf.php', 'backend_client.php'):
            shutil.copy(ROOT / 'web' / filename, self.root / filename)
        (self.root / 'konfiguration.php').write_text("<?php define('MYSQL_DATENBANK', 'testdb');")
        (self.root / 'run.php').write_text('''<?php
require 'csrf.php';
$_SERVER['REQUEST_METHOD'] = $argv[1];
$_POST = json_decode($argv[2], true);
if (($_POST['aktion_token'] ?? '') === 'VALID') $_POST['aktion_token'] = eablage_aktion_token();
require 'ollama.php';
echo "\\nSTATUS:" . (http_response_code() ?: 200);
''')
        self.requests = []
        self.status = 200
        self.body = {'model': 'qwen3.5:9b', 'message': 'OK'}
        case = self
        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args): pass
            def do_POST(self):
                case.requests.append((self.path, json.loads(self.rfile.read(int(self.headers['Content-Length'])))))
                self.send_response(case.status)
                self.end_headers()
                self.wfile.write(json.dumps(case.body).encode())
        self.server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.addCleanup(self.cleanup_server)

    def cleanup_server(self):
        self.server.shutdown(); self.server.server_close(); self.thread.join(2)

    def call(self, payload, method='POST'):
        raw = subprocess.check_output([PHP, '-d', 'display_errors=stderr', '-d', 'session.save_path=' + str(self.root), str(self.root / 'run.php'), method, json.dumps(payload)],
             cwd=self.root, text=True, timeout=5,
             env={**os.environ, 'BACKEND_URL': f'http://127.0.0.1:{self.server.server_port}'})
        body, status = raw.rsplit('\nSTATUS:', 1)
        try:
            return json.loads(body), int(status)
        except ValueError as exc:
            raise AssertionError('Unexpected PHP response: ' + raw) from exc

    def test_method_csrf_and_bad_identity_never_reach_backend(self):
        for method, payload, expected in [('GET', {}, 405), ('POST', {'action': 'check'}, 403),
                ('POST', {'action': 'suggest', 'aktion_token': 'VALID', 'filename': '../scan.pdf'}, 400)]:
            _, status = self.call(payload, method)
            self.assertEqual(status, expected)
        self.assertEqual(self.requests, [])

    def test_check_uses_server_database_and_preserves_safe_errors(self):
        body, status = self.call({'action': 'check', 'aktion_token': 'VALID', 'database': 'evil', 'url': 'http://evil'})
        self.assertEqual(status, 200)
        self.assertEqual(self.requests, [('/ollama/check', {'database': 'testdb'})])
        self.status = 400; self.body = {'detail': 'Lokale KI nicht erreichbar'}
        body, status = self.call({'action': 'check', 'aktion_token': 'VALID'})
        self.assertEqual((body['error'], status), ('Lokale KI nicht erreichbar', 400))

    def test_suggestion_only_forwards_document_identity(self):
        payload = {'action': 'suggest', 'aktion_token': 'VALID', 'filename': 'scan.pdf',
                   'job_id': '12', 'sha256': 'a'*64, 'text_sha256': 'b'*64, 'text': 'untrusted', 'model': 'evil'}
        _, status = self.call(payload)
        self.assertEqual(status, 200)
        self.assertEqual(self.requests[0], ('/ollama/suggest', {'database': 'testdb', 'filename': 'scan.pdf',
                          'job_id': 12, 'sha256': 'a'*64, 'text_sha256': 'b'*64}))

    def test_background_only_forwards_bound_identity_and_never_document_text(self):
        payload = {'action': 'background', 'aktion_token': 'VALID', 'filename': 'scan.pdf',
                   'job_id': '12', 'sha256': 'a'*64, 'text_sha256': 'b'*64,
                   'text': 'untrusted', 'database': 'evil', 'model': 'evil'}
        _, status = self.call(payload)
        self.assertEqual(status, 200)
        self.assertEqual(self.requests[0], ('/ollama/background', {'database': 'testdb', 'filename': 'scan.pdf',
                          'job_id': 12, 'sha256': 'a'*64, 'text_sha256': 'b'*64}))

    def test_current_fields_are_forwarded_but_unknown_and_invalid_values_are_rejected(self):
        payload = {'action': 'suggest', 'aktion_token': 'VALID', 'filename': 'scan.pdf',
                   'job_id': '12', 'sha256': 'a'*64, 'text_sha256': 'b'*64,
                   'current_fields': json.dumps({'partner': 'Stadtwerke', 'tags': 'Wärmepumpe'})}
        _, status = self.call(payload)
        self.assertEqual(status, 200)
        self.assertEqual(self.requests[-1][1]['current_fields'], {'partner': 'Stadtwerke', 'tags': 'Wärmepumpe'})
        for invalid in ['[]', '{bad', json.dumps({'evil': 'x'}), json.dumps({'partner': ['x']})]:
            _, status = self.call({**payload, 'current_fields': invalid})
            self.assertEqual(status, 400)
        self.assertEqual(len(self.requests), 1)

    def test_failed_proposal_forwards_diagnostic_without_changing_error(self):
        self.status = 400
        self.body = {'detail': 'Modellantwort ungültig', 'diagnostic': {'body': '{"model":"test"}', 'response_received': True}}
        payload = {'action': 'suggest', 'aktion_token': 'VALID', 'filename': 'scan.pdf', 'job_id': '12', 'sha256': 'a'*64, 'text_sha256': 'b'*64}
        body, status = self.call(payload)
        self.assertEqual(status, 400)
        self.assertEqual(body['error'], self.body['detail'])
        self.assertEqual(body['diagnostic'], self.body['diagnostic'])


if __name__ == '__main__': unittest.main()
