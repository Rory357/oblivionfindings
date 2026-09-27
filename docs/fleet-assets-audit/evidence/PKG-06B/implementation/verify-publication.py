"""Read-only staged artifact check before publishing the preserved design."""
import hashlib
import json
import subprocess
from pathlib import Path

mismatches = []
counts = {}
for manifest_path in sorted(Path('docs/fleet-assets-audit/evidence/PKG-06B').glob('v*/manifest.json')):
    manifest = json.loads(manifest_path.read_text(encoding='utf-8-sig'))
    counts[manifest_path.parent.name] = len(manifest['files'])
    for item in manifest['files']:
        blob = subprocess.check_output(['git', 'show', ':' + item['path']])
        if hashlib.sha256(blob).hexdigest().lower() != item['sha256'].lower():
            mismatches.append(item['path'])
staged = subprocess.check_output(['git', 'diff', '--cached', '--name-only'], text=True).splitlines()
forbidden = [path for path in staged if path == 'phpunit.pkg06b.xml' or path.startswith('storage/framework/pkg06b')]
assert not mismatches, mismatches
assert not forbidden, forbidden
print(json.dumps({'stagedFrozenFilesByVersion': counts, 'hashMismatches': mismatches, 'localRuntimeFilesStaged': forbidden, 'stagedFiles': len(staged)}))
