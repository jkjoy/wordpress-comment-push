#!/usr/bin/env python3
"""Build the WordPress install/update ZIP with its stable comment-push directory."""

import argparse
from pathlib import Path
import re
import zipfile


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--tag', help='Release tag, e.g. v1.2.0; must match the plugin version.')
    parser.add_argument('--output', default='dist/comment-push.zip')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    plugin = (root / 'comment-push.php').read_text(encoding='utf-8')
    version_match = re.search(r'^\s*\* Version:\s*(\d+\.\d+\.\d+)\s*$', plugin, re.MULTILINE)
    if not version_match:
        parser.error('Plugin header must contain a stable semantic version.')
    version = version_match.group(1)
    if "define('WPCPUSH_VERSION', '{}');".format(version) not in plugin:
        parser.error('WPCPUSH_VERSION does not match the plugin header.')
    readme = (root / 'readme.txt').read_text(encoding='utf-8')
    if not re.search(r'^Stable tag:\s*' + re.escape(version) + r'\s*$', readme, re.MULTILINE):
        parser.error('readme.txt Stable tag does not match the plugin header.')
    if args.tag and args.tag != 'v' + version:
        parser.error('Release tag must be v' + version)

    files = sorted(root.glob('*.php'))
    files += [root / name for name in ('README.md', 'readme.txt', 'LICENSE', 'THIRD_PARTY_NOTICES.md')]
    for directory in ('assets', 'tests'):
        files += sorted(path for path in (root / directory).rglob('*') if path.is_file())
    if not all(path.is_file() for path in files) or not (root / 'updater.php').is_file():
        parser.error('Required plugin files are missing.')
    if any(path.name.startswith('.') or path.suffix in ('.sqlite', '.log', '.zip') for path in files):
        parser.error('Unexpected runtime files in the package input.')

    output = Path(args.output)
    if not output.is_absolute():
        output = root / output
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
        for source in sorted(files):
            archive.write(source, 'comment-push/' + source.relative_to(root).as_posix())
    with zipfile.ZipFile(output) as archive:
        if archive.testzip() is not None:
            raise RuntimeError('ZIP integrity check failed.')
        if len(archive.namelist()) != len(files):
            raise RuntimeError('ZIP file count mismatch.')
    print('Built {}: version {}, {} files'.format(output, version, len(files)))


if __name__ == '__main__':
    main()
