"""Bounded Poppler/Tesseract extraction; original PDF bytes are never modified."""
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time

MAX_PDF = 50 * 1024 * 1024
MAX_TEXT = 16 * 1024 * 1024


def run(command, directory, deadline):
    with tempfile.TemporaryFile() as out, tempfile.TemporaryFile() as err:
        with subprocess.Popen(command, cwd=directory, stdout=out, stderr=err,
                              env={**os.environ, 'LC_ALL': 'C', 'LANG': 'C', 'OMP_THREAD_LIMIT': '1'}) as process:
            while process.poll() is None:
                if time.monotonic() >= deadline or os.fstat(out.fileno()).st_size > MAX_TEXT or os.fstat(err.fileno()).st_size > MAX_TEXT:
                    process.kill()
                    process.wait()
                    raise ValueError('PDF-Verarbeitung überschreitet Zeit- oder Ausgabegrenze.')
                time.sleep(.05)
            if process.returncode:
                raise ValueError('PDF-Werkzeug fehlgeschlagen. PDF und installierte OCR-Sprachen prüfen.')
        out.seek(0)
        data = out.read(MAX_TEXT + 1)
        if len(data) > MAX_TEXT:
            raise ValueError('Dokumenttext überschreitet das Größenlimit.')
        return data.decode('utf-8', errors='replace')


def extract(raw, mode='layout', ocr_requested=False):
    mode = mode if mode in ('layout', 'raw', 'default') else 'layout'
    result = dict(ok=False, text='', mode=mode, used_mode='', error='')
    try:
        if len(raw) > MAX_PDF:
            raise ValueError('PDF überschreitet 50 MiB.')
        enabled = os.getenv('EABLAGE_OCR_ENABLED', 'true').lower() not in ('0', 'false', 'off', 'no')
        if ocr_requested and not enabled:
            raise ValueError('OCR ist in dieser Instanz deaktiviert.')
        deadline = time.monotonic() + 90
        with tempfile.TemporaryDirectory(prefix='eablage-ocr-') as folder:
            pdf = str(Path(folder) / 'source.pdf')
            Path(pdf).write_bytes(raw)
            if not ocr_requested:
                for current in dict.fromkeys([mode, 'raw']):
                    options = [] if current == 'default' else ['-' + current]
                    text = run(['/usr/bin/pdftotext', *options, pdf, '-'], folder, deadline)
                    if text.strip():
                        text = re.sub(r' {2,}', ' ', text.translate(str.maketrans('', '', '#«^[]{}_´`°><|'))).replace(' ~', '~')
                        return dict(ok=True, text=text, mode=mode, used_mode=current, error='')
            if not enabled:
                raise ValueError('Es konnte kein Dokumenttext extrahiert werden; OCR ist deaktiviert.')
            languages = os.getenv('EABLAGE_OCR_LANGUAGES', 'deu+eng')
            if not re.fullmatch(r'[a-z]{3}(?:\+[a-z]{3})*', languages):
                raise ValueError('Ungültige OCR-Sprachkonfiguration.')
            info = run(['/usr/bin/pdfinfo', pdf], folder, deadline)
            pages = re.search(r'^Pages:\s+(\d+)', info, re.M)
            if not pages or not 1 <= int(pages[1]) <= 20:
                raise ValueError('OCR unterstützt in einem Durchlauf höchstens 20 Seiten.')
            texts, ocr_pages = [], 0
            for page in range(1, int(pages[1]) + 1):
                selection = ['-f', str(page), '-l', str(page)]
                text = '' if ocr_requested else run(
                    ['/usr/bin/pdftotext', '-layout', *selection, pdf, '-'], folder, deadline)
                if not text.strip():
                    prefix = str(Path(folder) / 'page')
                    run(['/usr/bin/pdftoppm', *selection, '-singlefile', '-scale-to', '2500', '-png', pdf, prefix], folder, deadline)
                    text = run(['/usr/bin/tesseract', prefix + '.png', 'stdout', '-l', languages], folder, deadline)
                    Path(prefix + '.png').unlink()
                    ocr_pages += 1
                texts.append(text.strip())
                if sum(len(t.encode('utf-8')) for t in texts) > MAX_TEXT:
                    raise ValueError('OCR-Text überschreitet das Größenlimit.')
            text = '\n\n'.join(texts)
            if not text.strip():
                raise ValueError('OCR hat keinen Text erkannt. Scanqualität und Ausrichtung prüfen.')
            return dict(ok=True, text=text, mode='ocr', used_mode='ocr', error='', ocr_pages=ocr_pages)
    except (OSError, ValueError) as exc:
        result['error'] = str(exc)
        return result
