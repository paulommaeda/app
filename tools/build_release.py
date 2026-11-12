#!/usr/bin/env python3
"""Build a canonical WordPress ZIP and its GitHub update manifest using only stdlib."""
from pathlib import Path
import hashlib
import html
import json
import re
import zipfile
from datetime import date

root = Path(__file__).resolve().parent.parent
main = (root / 'portal-de-servicos.php').read_text(encoding='utf-8')
version = re.search(r'^ \* Version:\s*(\d+\.\d+\.\d+)\s*$', main, re.M).group(1)
constant = re.search(r"define\( 'PDS_VERSION', '([^']+)' \)", main).group(1)
if constant != version:
    raise SystemExit('Plugin header and PDS_VERSION must match.')
readme = (root / 'readme.txt').read_text(encoding='utf-8')
if f'Stable tag: {version}\n' not in readme:
    raise SystemExit('Update Stable tag in readme.txt before building.')
release_dir = root / 'releases'
release_dir.mkdir(exist_ok=True)
archive = release_dir / f'portal-de-servicos-{version}.zip'
files = [root / 'portal-de-servicos.php', root / 'readme.txt']
for directory in ('includes', 'assets', 'templates', 'docs'):
    files += [p for p in (root / directory).rglob('*') if p.is_file()]
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as output:
    for file in sorted(files):
        if file.is_symlink():
            raise SystemExit('Do not distribute symlinks.')
        name = str(Path('portal-de-servicos') / file.relative_to(root))
        info = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        output.writestr(info, file.read_bytes())
changelog = []
in_list = False
for line in readme.split('== Changelog ==', 1)[-1].strip().splitlines():
    line = line.strip()
    if not line:
        continue
    if line.startswith('='):
        if in_list:
            changelog.append('</ul>')
            in_list = False
        changelog.append('<h4>' + html.escape(line.strip('= ')) + '</h4>')
    else:
        if not in_list:
            changelog.append('<ul>')
            in_list = True
        changelog.append('<li>' + html.escape(line.lstrip('* ')) + '</li>')
if in_list:
    changelog.append('</ul>')
manifest = {
    'name': 'Portal de Serviços',
    'version': version,
    'download_url': f'https://raw.githubusercontent.com/paulommaeda/app/main/releases/{archive.name}',
    'sha256': hashlib.sha256(archive.read_bytes()).hexdigest(),
    'requires': '6.6', 'requires_php': '8.0', 'tested': '7.1',
    'last_updated': date.today().isoformat(),
    'changelog': ''.join(changelog),
}
(root / 'update.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
print(archive)
print(manifest['sha256'])
