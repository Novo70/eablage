"""HTTP check against an explicitly supplied disposable Apache web image."""
import sys
import time
from urllib.error import HTTPError, URLError
from urllib.request import urlopen

base = sys.argv[1].rstrip('/')
for attempt in range(50):
    try:
        with urlopen(base + '/app.css', timeout=2):
            break
    except (URLError, ConnectionError):
        if attempt == 49:
            raise
        time.sleep(0.2)

for path, status in [('/app.css', 200), ('/missing-resource', 404), ('/Dockerfile', 403),
                     ('/apache-security.conf', 403), ('/uploads.ini', 403),
                     ('/vendor/pdfjs/web/viewer.html', 200), ('/vendor/pdfjs/build/pdf.mjs', 200)]:
    try:
        response = urlopen(base + path, timeout=5)
    except HTTPError as error:
        response = error
    with response:
        assert response.status == status, (path, response.status)
        for name, value in [('X-Content-Type-Options', 'nosniff'), ('Referrer-Policy', 'same-origin'), ('X-Frame-Options', 'SAMEORIGIN')]:
            assert response.headers.get_all(name) == [value], (path, name, response.headers)
        if path.endswith('.mjs'):
            assert response.headers.get_content_type() == 'application/javascript'
    print(f'{path}: {status}, headers OK')
