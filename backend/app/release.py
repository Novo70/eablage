"""Container-local copy generated from the repository's release.json."""
import json
from pathlib import Path

_release = json.loads(Path(__file__).with_name('release.json').read_text())
APP_VERSION = _release['version']
RELEASE_DATE = _release['date']
