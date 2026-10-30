"""Verify the published Fleet successor without changing approved Profile source."""
import hashlib
import json
import subprocess
from pathlib import Path

base = '554425e8dddd1b71bca0e81b6d5ddaf9d8f6459f'
prior_base = '64995efca1b067814a53f93fae962028e6510eb1'
approved = 'cb8320e623ffc972f7d9d9a420f8b59a2a430188'
folder = Path(__file__).parent
roots = ('app/', 'database/', 'resources/', 'routes/', 'tests/', 'design_styles/')
config = {'.gitattributes', '.gitignore', 'DESIGN.md', 'eslint.config.js', 'package.json', 'package-lock.json'}


def git(*args):
    return subprocess.check_output(['git', *args])


incoming = git('diff', '--name-only', prior_base, base).decode().splitlines()
assert len(incoming) == 7 and all(path.startswith('resources/js/') for path in incoming)
for path in incoming:
    assert git('show', ':' + path) == git('show', base + ':' + path), path
changed = git('diff', '--cached', '--name-only', approved).decode().splitlines()
changed_source = [path for path in changed if path.startswith(roots) or path in config]
assert sorted(changed_source) == sorted(incoming), changed_source

previous = json.loads((folder / 'revision-1-source-sha256.json').read_text(encoding='utf-8-sig'))
for item in previous['files']:
    staged = git('show', ':' + item['path'])
    assert hashlib.sha256(staged).hexdigest() == item['sha256'], item['path']

paths = git('diff', '--cached', '--name-only', base).decode().splitlines()
source = [path for path in paths if path.startswith(roots) or path in config]
hashes = []
for path in source:
    staged = git('show', ':' + path)
    assert staged == Path(path).read_bytes().replace(b'\r\n', b'\n'), 'Unstaged source: ' + path
    hashes.append({'path': path, 'sha256': hashlib.sha256(staged).hexdigest()})
assert len(hashes) == 76
result = {'publishedBase': base, 'approvedCandidate': approved, 'sourceFiles': len(hashes),
          'hashBasis': 'Git index blobs (LF source)', 'approvedSourceUnchanged': True,
          'publishedSuccessorFilesIdentical': incoming, 'files': hashes}
(folder / 'revision-2-source-sha256.json').write_text(json.dumps(result, indent=2) + '\n', encoding='utf-8')
print(json.dumps({key: value for key, value in result.items() if key != 'files'}))
