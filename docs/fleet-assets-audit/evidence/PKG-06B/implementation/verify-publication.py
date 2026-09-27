"""Read-only staged artifact check before publishing the preserved design."""
import hashlib
import json
import subprocess
from pathlib import Path

manifest = json.loads(Path('docs/fleet-assets-audit/evidence/PKG-06B/v9/manifest.json').read_text(encoding='utf-8-sig'))
mismatches = []
for item in manifest['files']:
    blob = subprocess.check_output(['git', 'show', ':' + item['path']])
    if hashlib.sha256(blob).hexdigest().lower() != item['sha256'].lower():
        mismatches.append(item['path'])
staged = subprocess.check_output(['git', 'diff', '--cached', '--name-only'], text=True).splitlines()
forbidden = [path for path in staged if path == 'phpunit.pkg06b.xml' or path.startswith('storage/framework/pkg06b')]
assert not mismatches, mismatches
assert not forbidden, forbidden
print(json.dumps({'stagedFrozenFiles': len(manifest['files']), 'hashMismatches': mismatches, 'localRuntimeFilesStaged': forbidden, 'stagedFiles': len(staged)}))
