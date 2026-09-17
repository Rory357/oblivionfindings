from pathlib import Path
import hashlib
import json
import os
import shutil
import subprocess

root = Path(r'C:\Users\steph\Herd\oblivionfindings')
candidate = Path(r'C:\Users\steph\.codex\visualizations\2026\09\12\01a094e0-dd40-7b73-b7c8-d0216a4a0e5c\myday-publish')
published = '607cbb8343c158ab715fa4c376a9f1fdc81d6069'

def git(*args, env=None, data=None):
    return subprocess.check_output(['git', *args], cwd=root, env=env, input=data)

assert git('branch', '--show-current').strip() == b'main'
assert git('diff', '--cached', '--name-only').strip() == b'', 'Preserve concurrent staged work.'
previous = git('rev-parse', 'HEAD').decode().strip()
assert previous == 'f8bb0acc8f145cdefe7f80ec4f39917fc09e611b', 'Local main changed; re-review.'
index_path = root / '.git/index'
index_hash = hashlib.sha256(index_path.read_bytes()).hexdigest()
merge = subprocess.run(['git', 'merge-tree', '--write-tree', previous, published], cwd=root, stdout=subprocess.PIPE)
merge_output = merge.stdout.decode('utf-8')
assert merge.returncode == 1 and merge_output.count('CONFLICT (content)') == 1
assert 'CONFLICT (content): Merge conflict in resources/js/components/wizard/shell.test.tsx' in merge_output
merged_tree = merge_output.splitlines()[0]

# Resolve the one overlap by retaining both existing regression tests and the
# already verified assertion for the approved shared button appearance.
wizard = 'resources/js/components/wizard/shell.test.tsx'
wizard_body = (root / wizard).read_bytes()
for required in (b'disables only explicitly unavailable steps', b'lets a replacement workspace retain focus', b"'btn-soft-primary'"):
    assert required in wizard_body

temporary_index = root / '.git/myday-publish-merge.index'
assert not temporary_index.exists()
isolated_env = dict(os.environ, GIT_INDEX_FILE=str(temporary_index))
git('read-tree', merged_tree, env=isolated_env)
blob = git('hash-object', '-w', '--stdin', data=wizard_body).decode().strip()
git('update-index', '--cacheinfo', '100644,' + blob + ',' + wizard, env=isolated_env)
tree = git('write-tree', env=isolated_env).decode().strip()
assert b'<<<<<<<' not in git('show', tree + ':' + wizard)

# The merged tree can only change the paths in this published release.
published_paths = set(git('diff', '--name-only', published + '^', published).decode('utf-8').splitlines())
merged_paths = set(git('diff', '--name-only', previous, tree).decode('utf-8').splitlines())
assert merged_paths <= published_paths

existing = {p: hashlib.sha256((root / p).read_bytes()).hexdigest() for p in merged_paths if (root / p).is_file()}
for path in sorted(merged_paths):
    if not (root / path).exists():
        assert path.startswith('docs/'), 'Unexpected missing application file: ' + path
        (root / path).parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(candidate / path, root / path)

message = b'Merge published My Day redesign into local main\n\nRetain unpublished local IT history and all unrelated working changes.\n'
commit = git('commit-tree', tree, '-p', previous, '-p', published, data=message).decode().strip()
assert hashlib.sha256(index_path.read_bytes()).hexdigest() == index_hash, 'Concurrent index change; do not replace.'
git('update-ref', '-m', 'merge: published My Day redesign', 'refs/heads/main', commit, previous)
git('read-tree', commit)
temporary_index.unlink()
for path, digest in existing.items():
    assert hashlib.sha256((root / path).read_bytes()).hexdigest() == digest, path
assert git('diff', '--cached', '--name-only').strip() == b''
print(json.dumps({'published_commit': published, 'local_merge_commit': commit, 'existing_working_files_preserved': len(existing)}))
