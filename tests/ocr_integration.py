"""Local OCR integration using temporary generated PDFs; no DB/NAS access.
Requires Poppler, Tesseract deu+eng, Pillow and reportlab.
"""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import sys
import time
from unittest.mock import patch
from PIL import Image, ImageDraw, ImageFont
from reportlab.pdfgen import canvas
from reportlab.lib.utils import ImageReader

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'backend'))
from app.services.pdf_text import extract, run

@unittest.skipUnless(shutil.which('tesseract'), 'Tesseract required')
class OCRIntegration(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='eablage-ocr-test-')
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)
        self.image = Image.new('RGB', (1600, 900), 'white')
        font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', 55)
        ImageDraw.Draw(self.image).text((90, 160), 'Rechnung Musterfirma 12345', font=font, fill='black')

    def pdf(self, name, scanned=False, mixed=False, pages=1):
        path = self.directory / name
        c = canvas.Canvas(str(path), pagesize=(800, 450))
        if mixed:
            c.drawString(80, 300, 'Native text page 98765')
            c.showPage()
        for _ in range(pages):
            if scanned:
                c.drawImage(ImageReader(self.image), 0, 0, width=800, height=450)
            else:
                c.drawString(80, 300, 'Native text page 98765')
            c.showPage()
        c.save()
        return path

    def extract(self, path, force=False, **environment):
        before = hashlib.sha256(path.read_bytes()).hexdigest()
        temporary_before = set(Path(tempfile.gettempdir()).glob('eablage-ocr-*'))
        with patch.dict(os.environ, {'EABLAGE_OCR_ENABLED': 'true', **environment}):
            result = extract(path.read_bytes(), ocr_requested=force)
        self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(), before)
        self.assertEqual(set(Path(tempfile.gettempdir()).glob('eablage-ocr-*')), temporary_before)
        return result

    def test_scanned_pdf_automatic_ocr_and_original_unchanged(self):
        result = self.extract(self.pdf("scan ' ; document.pdf", scanned=True))
        self.assertTrue(result['ok'], result)
        self.assertEqual(result['used_mode'], 'ocr')
        self.assertIn('Musterfirma', result['text'])
        self.assertIn('12345', result['text'])

    def test_native_text_skips_ocr(self):
        result = self.extract(self.pdf('native.pdf'))
        self.assertTrue(result['ok'], result)
        self.assertNotEqual(result['used_mode'], 'ocr')
        self.assertIn('98765', result['text'])

    def test_explicit_mixed_pdf_recognizes_every_page(self):
        result = self.extract(self.pdf('mixed.pdf', scanned=True, mixed=True), force=True)
        self.assertTrue(result['ok'], result)
        self.assertEqual(result['ocr_pages'], 2)
        self.assertIn('98765', result['text'])
        self.assertIn('Musterfirma', result['text'])

    def test_explicit_ocr_replaces_wrong_existing_text_layer(self):
        path = self.directory / 'wrong-layer.pdf'
        c = canvas.Canvas(str(path), pagesize=(800, 450))
        c.drawImage(ImageReader(self.image), 0, 0, width=800, height=450)
        hidden = c.beginText(80, 300)
        hidden.setTextRenderMode(3)
        hidden.textLine('Wrong existing OCR layer 99999')
        c.drawText(hidden)
        c.save()
        automatic = self.extract(path)
        self.assertIn('99999', automatic['text'])
        self.assertNotEqual(automatic['used_mode'], 'ocr')
        forced = self.extract(path, force=True)
        self.assertTrue(forced['ok'], forced)
        self.assertEqual(forced['ocr_pages'], 1)
        self.assertIn('Musterfirma', forced['text'])
        self.assertIn('12345', forced['text'])
        self.assertNotIn('99999', forced['text'])

    def test_disabled_ocr_does_not_read_scan(self):
        result = self.extract(self.pdf('disabled.pdf', scanned=True), EABLAGE_OCR_ENABLED='false')
        self.assertFalse(result['ok'])
        self.assertEqual(result['text'], '')

    def test_page_limit_returns_no_partial_result(self):
        result = self.extract(self.pdf('large.pdf', scanned=True, pages=21), force=True)
        self.assertFalse(result['ok'])
        self.assertEqual(result['text'], '')
        self.assertIn('20 Seiten', result['error'])

    def test_missing_language_and_invalid_pdf_fail_cleanly(self):
        result = self.extract(self.pdf('language.pdf', scanned=True), EABLAGE_OCR_LANGUAGES='xxx')
        self.assertFalse(result['ok'])
        self.assertEqual(result['text'], '')
        bad = self.directory/'invalid.pdf'
        bad.write_bytes(b'not a PDF')
        self.assertFalse(self.extract(bad, force=True)['ok'])

    def test_process_timeout_is_bounded(self):
        with self.assertRaisesRegex(ValueError, 'Zeit-'):
            run([sys.executable, '-c', 'import time; time.sleep(1)'], str(self.directory), time.monotonic() + .1)

if __name__ == '__main__':
    unittest.main()
