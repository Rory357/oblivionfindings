"""Verify the staged candidate against the actually published Maps/Main base."""
import hashlib
import json
import subprocess
from pathlib import Path

base = '64995efca1b067814a53f93fae962028e6510eb1'
reviewed = '173a49bb8678bd0f4f5f7ed0f6e83e3f19b768c8'
register_base = 'b05702d7208c88d9cffb9c092df23d3b02300b1e'
folder = Path(__file__).parent
roots = ('app/', 'database/', 'resources/', 'routes/', 'tests/', 'design_styles/')
config = {'.gitattributes', '.gitignore', 'DESIGN.md', 'eslint.config.js', 'package.json', 'package-lock.json'}


def git(*args):
    return subprocess.check_output(['git', *args])


paths = git('diff', '--cached', '--name-only', base).decode().splitlines()
source = [path for path in paths if path.startswith(roots) or path in config]
hashes = []
for path in source:
    staged = git('show', ':' + path)
    assert staged == Path(path).read_bytes().replace(b'\r\n', b'\n'), 'Unstaged source: ' + path
    hashes.append({'path': path, 'sha256': hashlib.sha256(staged).hexdigest()})

published_paths = git('diff', '--name-only', register_base, base).decode().splitlines()
published_source = [path for path in published_paths if path.startswith(roots) or path in config]
changed = [path for path in published_source if git('show', base + ':' + path) != git('show', ':' + path)]
assert set(changed) == {'app/Http/Controllers/FleetAssets/AssetController.php',
                       'resources/js/pages/fleet-assets/assets/show.tsx', 'routes/fleet-assets.php'}, changed
for path in ['resources/js/pages/fleet-assets/assets/index.tsx',
             'app/Services/Assets/AssetStocktakeService.php',
             'database/migrations/2026_09_27_140000_retain_stocktake_history_asset_references.php',
             'tests/Feature/FleetAssets/TransportWorkspaceTest.php']:
    assert git('show', base + ':' + path) == git('show', ':' + path), path

result = {'publishedBase': base, 'previousReviewedCandidate': reviewed,
          'hashBasis': 'Git index blobs (LF source)', 'sourceFiles': len(hashes), 'files': hashes,
          'publishedMapsSourceFiles': len(published_source), 'reconciledMapsFiles': changed,
          'otherPublishedMapsSourceUnchanged': True, 'registerAndTransportPreservation': True}
(folder / 'revision-1-source-sha256.json').write_text(json.dumps(result, indent=2) + '\n', encoding='utf-8')
print(json.dumps({key: value for key, value in result.items() if key != 'files'}))
