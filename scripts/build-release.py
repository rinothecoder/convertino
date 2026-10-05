#!/usr/bin/env python3
"""Build an installable ZIP and its updater manifest. Never publishes anything."""
import argparse
import hashlib
import json
from pathlib import Path
import re
from urllib.parse import urlsplit
import zipfile

ROOT = Path(__file__).resolve().parents[1]
FILES = ["convertino.php", "includes-core.php", "includes-admin.php", "includes-updates.php",
         "assets/admin.css", "assets/admin.js", "assets/tracker.js", "assets/convertino-icon.svg",
         "assets/convertino-sidebar.svg", "readme.txt", "LICENSE.txt"]


def build(output, package_url, homepage, tested, changelog):
    header = (ROOT / "convertino.php").read_text()
    version = re.search(r"\* Version: (\d+\.\d+\.\d+)\s*$", header, re.M).group(1)
    core = (ROOT / "includes-core.php").read_text()
    readme = (ROOT / "readme.txt").read_text()
    if f"const VERSION = '{version}';" not in core or f"Stable tag: {version}\n" not in readme:
        raise ValueError("Plugin header, core and readme versions must match.")
    url = urlsplit(package_url)
    if url.scheme != "https" or not url.hostname or url.username or url.password or url.fragment or not url.path.endswith(".zip"):
        raise ValueError("Use a public HTTPS ZIP URL without credentials.")
    if not re.fullmatch(r"\d+\.\d+(?:\.\d+)?", tested):
        raise ValueError("Use a WordPress version for --tested.")
    output.mkdir(parents=True, exist_ok=True)
    archive = output / f"convertino-{version}.zip"
    with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as z:
        for name in FILES:
            # Stable metadata makes repeat builds of the same release identical.
            info = zipfile.ZipInfo("convertino/" + name, (2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            z.writestr(info, (ROOT / name).read_bytes())
    manifest = {
        "slug": "convertino", "version": version, "download_url": package_url,
        "sha256": hashlib.sha256(archive.read_bytes()).hexdigest(),
        "requires": "6.5", "requires_php": "8.0", "tested": tested,
        "homepage": homepage, "changelog": changelog,
    }
    (output / "update.json").write_text(json.dumps(manifest, indent=2) + "\n")
    print(f"Built {archive}\nSHA256 {manifest['sha256']}")
    return manifest


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--package-url", required=True)
    parser.add_argument("--homepage", default="https://github.com/rinothecoder/convertino/")
    parser.add_argument("--tested", required=True)
    parser.add_argument("--changelog", default="<p>Add WordPress update notices and verified release downloads.</p>")
    args = parser.parse_args()
    build(args.output, args.package_url, args.homepage, args.tested, args.changelog)
