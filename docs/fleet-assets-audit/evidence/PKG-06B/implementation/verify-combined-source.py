"""Record the exact staged runtime/design-rule delta against published PKG-06A."""
import hashlib
import json
import subprocess
from pathlib import Path

base = 'b05702d7208c88d9cffb9c092df23d3b02300b1e'
folder = Path(__file__).parent
paths = subprocess.check_output(['git', 'diff', '--cached', '--name-only', base], text=True).splitlines()
roots = ('app/', 'database/', 'resources/', 'routes/', 'tests/', 'design_styles/')
config = {'.gitattributes', '.gitignore', 'DESIGN.md', 'eslint.config.js', 'package.json', 'package-lock.json'}
source = [path for path in paths if path.startswith(roots) or path in config]
hashes = []
for path in source:
    staged = subprocess.check_output(['git', 'show', ':' + path])
    # Git stores normalized LF for source. Raw frozen references are checked separately.
    work = Path(path).read_bytes().replace(b'\r\n', b'\n')
    assert staged == work, 'Unstaged source differs: ' + path
    hashes.append({'path': path, 'sha256': hashlib.sha256(staged).hexdigest()})
result = {'publishedBase': base, 'sourceFiles': len(hashes), 'hashBasis': 'Git index blobs (LF source)', 'files': hashes}
(folder / 'combined-source-sha256.json').write_text(json.dumps(result, indent=2) + '\n', encoding='utf-8')
register_paths = Path('docs/fleet-assets-audit/implementation/PKG-06A/final-main/application-files.txt').read_text(encoding='utf-8-sig').splitlines()
preservation = {'base': base, 'unchanged': [], 'reconciled': []}
for path in register_paths:
    original = subprocess.check_output(['git', 'show', base + ':' + path])
    staged = subprocess.check_output(['git', 'show', ':' + path])
    preservation['unchanged' if original == staged else 'reconciled'].append(path)
for path in ['resources/js/pages/fleet-assets/assets/index.tsx', 'database/migrations/2026_09_27_140000_retain_stocktake_history_asset_references.php', 'app/Services/Assets/AssetStocktakeService.php']:
    assert path in preservation['unchanged'], path
transport = 'tests/Feature/FleetAssets/TransportWorkspaceTest.php'
assert subprocess.check_output(['git', 'show', base + ':' + transport]) == subprocess.check_output(['git', 'show', ':' + transport])
preservation['canonicalTransportFixtureUnchanged'] = True
(folder / 'combined-register-preservation.json').write_text(json.dumps(preservation, indent=2) + '\n', encoding='utf-8')
print(json.dumps({'base': base, 'sourceFiles': len(hashes), 'workingSourceMatchesIndex': True}))
