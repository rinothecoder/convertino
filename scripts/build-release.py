#!/usr/bin/env python3
"""Build an installable Convertino ZIP for initial/manual installation. No publishing."""
import argparse
import hashlib
from pathlib import Path
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]
FILES = ['convertino.php', 'includes-core.php', 'includes-admin.php', 'includes-updates.php',
         'assets/admin.css', 'assets/admin.js', 'assets/tracker.js', 'assets/convertino-icon.svg',
         'assets/convertino-sidebar.svg', 'readme.txt', 'LICENSE.txt']


def build(output):
    version = re.search(r'\* Version: (\d+\.\d+\.\d+)\s*$', (ROOT / 'convertino.php').read_text(), re.M).group(1)
    if f"const VERSION = '{version}';" not in (ROOT / 'includes-core.php').read_text() or f'Stable tag: {version}\n' not in (ROOT / 'readme.txt').read_text():
        raise ValueError('Plugin header, core and readme versions must match.')
    output.mkdir(parents=True, exist_ok=True)
    archive = output / f'convertino-{version}.zip'
    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as z:
        for name in FILES:
            info = zipfile.ZipInfo('convertino/' + name, (2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            z.writestr(info, (ROOT / name).read_bytes())
    checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
    (output / (archive.name + '.sha256')).write_text(checksum + '  ' + archive.name + '\n')
    print(f'Built {archive}\nSHA256 {checksum}')
    return archive


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    build(parser.parse_args().output)
