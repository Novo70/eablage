"""Exercise shared PHP HTTP transport against a local server, without database access."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import threading
import time
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN', shutil.which('php'))

@unittest.skipUnless(PHP, 'PHP CLI required')
class BackendClient(unittest.TestCase):
    def setUp(self):
        self.requests=[]
        self.status=200
        self.body=b'{"ok":true}'
        self.delay=0
        case=self
        class Handler(BaseHTTPRequestHandler):
            def log_message(self,*args): pass
            def do_GET(self): self.reply()
            def do_POST(self): self.reply()
            def reply(self):
                raw=self.rfile.read(int(self.headers.get('Content-Length',0)))
                case.requests.append((self.command,self.path,raw))
                time.sleep(case.delay)
                self.send_response(case.status)
                self.send_header('Location','/must-not-follow')
                self.end_headers()
                try:self.wfile.write(case.body)
                except BrokenPipeError:pass
        self.server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
        self.thread=threading.Thread(target=self.server.serve_forever,daemon=True)
        self.thread.start()
        self.addCleanup(self.cleanup)
    def cleanup(self):
        self.server.shutdown();self.server.server_close();self.thread.join(3)
    def call(self,method='GET',bad=False,substitute=False,configured=True):
        code="require $argv[1]; $payload=$argv[2]==='POST'?['text'=>$argv[3]==='bad'?\"\\xFF\":'Ä']:null; echo json_encode(eablage_backend_request($argv[2],'/example',$payload,1,$argv[4]==='yes'?JSON_INVALID_UTF8_SUBSTITUTE:0));"
        return json.loads(subprocess.check_output([PHP,'-r',code,str(ROOT/'web/backend_client.php'),method,'bad' if bad else 'ok','yes' if substitute else 'no'],env={**os.environ,'BACKEND_URL':f'http://127.0.0.1:{self.server.server_port}' if configured else ''},text=True,timeout=4))
    def test_get_post_and_strict_encoding(self):
        self.assertTrue(self.call()['ok'])
        self.assertTrue(self.call('POST')['ok'])
        self.assertEqual(self.requests[0],('GET','/example',b''))
        self.assertEqual(json.loads(self.requests[1][2]),{'text':'Ä'})
        self.assertFalse(self.call('POST',bad=True)['ok'])
        self.assertEqual(len(self.requests),2)
        self.assertTrue(self.call('POST',bad=True,substitute=True)['ok'])
        self.assertEqual(json.loads(self.requests[-1][2]),{'text':'�'})
    def test_http_errors_keep_structured_detail_and_no_redirect(self):
        self.status=409; self.body=b'{"detail":{"message":"blocked","rules":["R0001"]}}'
        reply=self.call('POST')
        self.assertFalse(reply['ok']); self.assertEqual(reply['http_status'],409)
        self.assertEqual(reply['data']['detail']['rules'],['R0001'])
        self.assertIn('blocked',reply['error'])
        self.status=302; self.body=b'{}'
        self.assertFalse(self.call()['ok'])
        self.assertEqual(len(self.requests),2)
    def test_invalid_json_and_missing_configuration(self):
        self.body=b'not json'
        self.assertIn('JSON-Antwort',self.call()['error'])
        self.assertFalse(self.call(configured=False)['ok'])
        self.assertEqual(len(self.requests),1)
    def test_timeout_never_retries_post(self):
        self.delay=1.4
        self.assertIn('Zeitlimit',self.call('POST')['error'])
        self.assertEqual(len(self.requests),1)

if __name__=='__main__':unittest.main()
