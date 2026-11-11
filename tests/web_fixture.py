"""Shared real web tree for PHP integration tests; inject test configuration later."""
from pathlib import Path
import shutil

ROOT = Path(__file__).resolve().parents[1]


def copy_web(target):
    def excluded(directory, names):
        return [name for name in names if
                (name.lower().startswith('konfiguration') and name.lower().endswith('.php'))
                or Path(directory, name).is_symlink()]
    shutil.copytree(ROOT / 'web', target, dirs_exist_ok=True, ignore=excluded)
