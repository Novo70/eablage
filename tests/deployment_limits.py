"""Deployment gate: PHP upload limits and Python's PDF limit must agree."""
import configparser
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'backend'))
from app.services.pdf_text import MAX_PDF
PHP = os.environ.get('PHP_BIN', shutil.which('php'))

class DeploymentLimits(unittest.TestCase):
    @unittest.skipUnless(PHP, 'PHP CLI required for deployment limit verification')
    def test_limits_and_effective_post_limit(self):
        config = configparser.ConfigParser()
        config.read_string('[php]\n' + (ROOT/'web/uploads.ini').read_text())
        def request(upload, post):
            code = "require $argv[1]; echo json_encode([EABLAGE_UPLOAD_MAX_BYTES,eablage_upload_max_bytes(),eablage_upload_limit_text(),ini_parse_quantity(ini_get('upload_max_filesize'))]);"
            raw = subprocess.check_output([PHP, '-d', f'upload_max_filesize={upload}', '-d', f'post_max_size={post}', '-r', code, str(ROOT/'web/upload_funktionen.php')], text=True)
            return json.loads(raw)
        configured = request(config['php']['upload_max_filesize'], config['php']['post_max_size'])
        self.assertEqual(configured[:2], [MAX_PDF, MAX_PDF], 'Update PHP, uploads.ini and Python together')
        self.assertEqual(configured[3], MAX_PDF, 'PHP upload_max_filesize must match the application limit')
        self.assertEqual(configured[2], '50 MiB')
        self.assertEqual(request('8M', '4M')[1], 4*1024*1024-64*1024)
        self.assertEqual(request('2M', '55M')[1], 2*1024*1024)
        self.assertEqual(request('50M', '0')[1], MAX_PDF)
        self.assertEqual(request('50M', '32K')[1], 0)

if __name__ == '__main__': unittest.main()
