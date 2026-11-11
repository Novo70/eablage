"""Check release consistency; --write updates generated container metadata only."""
import argparse
from datetime import date
import json
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--write', action='store_true')
    args = parser.parse_args()
    source = (ROOT / 'release.json').read_bytes()
    metadata = json.loads(source)
    version = metadata['version']
    released = date.fromisoformat(metadata['date'])
    if not re.fullmatch(r'\d+\.\d+\.\d+', version):
        raise SystemExit('Invalid release version')
    for target in ['web/release.json', 'backend/app/release.json']:
        path = ROOT / target
        if args.write:
            path.write_bytes(source)
        if not path.is_file() or path.read_bytes() != source:
            raise SystemExit(f'{target} differs: run python scripts/release_metadata.py --write')
    changelog = (ROOT / 'changelog.txt').read_text()
    latest = re.search(r'^## (\d+\.\d+\.\d+) – .+ – (\d{2}\.\d{2}\.\d{4})$', changelog, re.M)
    if not latest or latest.groups() != (version, released.strftime('%d.%m.%Y')):
        raise SystemExit('Latest release heading in changelog.txt differs from release.json')
    readme = (ROOT / 'README.md').read_text()
    if f'Diese Dokumentation beschreibt eAblage in der Version {version}.' not in readme:
        raise SystemExit('README release differs')
    for name in ['schema', 'seed']:
        sql = (ROOT / f'sql/{name}.sql').read_text()
        if f'-- Target: eAblage {version} ' not in sql:
            raise SystemExit(f'{name} release header differs')
    restore = (ROOT / 'web/datenbank_wiederherstellung.php').read_text()
    accepted = re.search(r'const EABLAGE_RESTORE_VERSIONS = \[(.*?)\];', restore)
    if not accepted or f"'{version}'" not in accepted.group(1):
        raise SystemExit('Current release backups are not accepted for restore')
    # Direct requirements and their hashed lock must not silently drift apart.
    locked = (ROOT / 'backend/requirements.txt').read_text()
    for requirement in (ROOT / 'backend/requirements.in').read_text().splitlines():
        if requirement and not requirement.startswith('#') and requirement not in locked:
            raise SystemExit(f'Runtime lock differs from direct requirement: {requirement}')
    print(f'Release metadata: {version} / {released.isoformat()} OK')


if __name__ == '__main__':
    main()
