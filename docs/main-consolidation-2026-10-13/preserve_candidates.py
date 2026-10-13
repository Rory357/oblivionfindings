"""Preserve frozen candidate overlays without applying them to the running app.

Reads source worktrees only. Creates verified, non-executable archives in this
directory; no checkout, merge, reset, index update, test, database or provider call.
"""
from pathlib import Path
import hashlib
import io
import json
import re
import subprocess
import sys
import tarfile

MAIN = Path('C:/Users/steph/Herd/oblivionfindings')
OUT = MAIN / 'docs/main-consolidation-2026-10-13'
ROOTS = {
    'control-room': Path('C:/Users/steph/.codex/worktrees/control-room-delivery/oblivionfindings'),
    'health-safety': Path('C:/Users/steph/.codex/worktrees/health-safety-workspace/oblivionfindings'),
}
SENSITIVE = re.compile(rb'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,}|AKIA[0-9A-Z]{16}')

def git(root, *args, allowed=(0,)):
    p = subprocess.run(['git', '-C', str(root), *args], capture_output=True)
    if p.returncode not in allowed:
        raise RuntimeError(p.stderr.decode('utf-8', 'replace')[:1000])
    return p.stdout

def paths(root, *args):
    return [x.decode('utf-8') for x in git(root, *args).split(b'\0') if x]

def digest(data):
    return hashlib.sha256(data).hexdigest()

def excluded(path):
    p = Path(path)
    parts = [s.lower() for s in p.parts]
    return (any(s in {'node_modules', 'vendor', '.git', '__pycache__'} for s in parts)
            or p.name.lower().startswith('.env') or p.suffix.lower() in {'.sqlite', '.sqlite3', '.db', '.key', '.pem'}
            or ('public' in parts and any(s.startswith('build') for s in parts))
            or ('storage' in parts and any(s in {'framework', 'sessions', 'cache'} for s in parts)))

def candidate(name, root):
    head = git(root, 'rev-parse', 'HEAD').decode().strip()
    git(MAIN, 'merge-base', '--is-ancestor', head, 'origin/main')
    tracked = paths(root, 'diff', '--name-only', '-z', 'HEAD')
    untracked = paths(root, 'ls-files', '--others', '--exclude-standard', '-z')
    entries = sorted(set(tracked + untracked))
    extra = []
    # Preserve owned verification helpers and saved results, never Laravel logs.
    logs = root / 'storage/logs'
    if logs.exists() and name == 'health-safety':
        for p in logs.iterdir():
            if p.is_file() and p.name.startswith(('participation-', 'health-safety-', 'hs-', 'incident-')):
                if p.suffix.lower() in {'.json', '.txt', '.md', '.ps1', '.py', '.cjs', '.mjs', '.js', '.log'}:
                    extra.append(p.relative_to(root).as_posix())
    entries = sorted(set(entries + extra))
    patch = git(root, 'diff', '--binary', '--full-index', '--no-ext-diff', '--no-textconv', '--no-renames', 'HEAD')
    return root, entries, {'base_commit': head, 'base_in_origin_main_history': True,
                           'tracked_paths': tracked, 'untracked_paths': untracked,
                           'extra_evidence_paths': extra}, patch

def workforce():
    entries, skipped_dirs = [], []
    result_root = MAIN / 'test-results'
    extras = [p for p in result_root.iterdir() if p.name.startswith(('timesheet-post-lint', 'timesheet-owned-eslint', 'run-timesheet-post-lint', 'run-timesheet-owned-eslint'))]
    for folder in sorted([*result_root.glob('workforce-*'), *extras]):
        if folder.is_file():
            entries.append(folder.relative_to(MAIN).as_posix())
            continue
        if not folder.is_dir():
            continue
        if 'source-mirror' in folder.name:
            skipped_dirs.append(folder.relative_to(MAIN).as_posix())
            continue
        for p in folder.rglob('*'):
            if p.is_file():
                entries.append(p.relative_to(MAIN).as_posix())
    return MAIN, sorted(set(entries)), {'purpose': 'Ignored Workforce proposals and evidence; not installed application code',
                                  'excluded_redundant_source_mirror_directories': skipped_dirs}, None

def archive(name, root, entries, metadata, patch, dry_run):
    selected, omissions, deleted, secret_hits = [], [], [], []
    total = 0
    for rel in entries:
        p = root / rel
        if excluded(rel):
            omissions.append({'path': rel, 'reason': 'environment, database, private key, dependency or generated build/cache'})
            continue
        if not p.exists():
            deleted.append(rel)
            continue
        if p.is_symlink() or not p.is_file():
            omissions.append({'path': rel, 'reason': 'not a regular source file'})
            continue
        data = p.read_bytes()
        if len(data) > 25 * 1024 * 1024:
            raise RuntimeError(f'Oversize file needs explicit selection: {rel}')
        if SENSITIVE.search(data):
            secret_hits.append(rel)
        selected.append({'path': rel, 'bytes': len(data), 'sha256': digest(data)})
        total += len(data)
    if secret_hits:
        raise RuntimeError('Possible credential material; inspect filenames only: ' + json.dumps(secret_hits))
    manifest = {'name': name, 'source_root': str(root), 'status': 'PRESERVED_CANDIDATE_NOT_RUNTIME_INTEGRATED',
                **metadata, 'files': selected, 'deleted_paths': deleted, 'omissions': omissions,
                'patch_sha256': digest(patch) if patch is not None else None}
    if dry_run:
        return {'name': name, 'files': len(selected), 'bytes': total, 'omitted': len(omissions),
                'deleted': len(deleted), 'base': metadata.get('base_commit')}
    target = OUT / (name + '-unfinished-source.tar.xz')
    if target.exists():
        raise RuntimeError(f'Refusing to overwrite existing archive: {target}')
    with tarfile.open(target, 'w:xz', preset=6) as tar:
        def add(rel, data):
            info = tarfile.TarInfo(rel)
            info.size = len(data)
            info.mode = 0o644
            info.mtime = 0
            tar.addfile(info, io.BytesIO(data))
        add('manifest.json', (json.dumps(manifest, indent=2) + '\n').encode())
        if patch is not None:
            add('tracked-changes.patch', patch)
        for f in selected:
            data = (root / f['path']).read_bytes()
            if digest(data) != f['sha256']:
                raise RuntimeError('Source changed during preservation: ' + f['path'])
            add('files/' + f['path'], data)
    # Verify saved archive bytes and compare every captured file to frozen source.
    with tarfile.open(target, 'r:xz') as tar:
        for f in selected:
            if digest(tar.extractfile('files/' + f['path']).read()) != f['sha256']:
                raise RuntimeError('Archive verification failed: ' + f['path'])
            if digest((root / f['path']).read_bytes()) != f['sha256']:
                raise RuntimeError('Source changed after preservation: ' + f['path'])
        if patch is not None and digest(tar.extractfile('tracked-changes.patch').read()) != digest(patch):
            raise RuntimeError('Saved patch verification failed')
    manifest_path = OUT / (name + '-manifest.json')
    manifest_path.write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')
    return {'name': name, 'archive': target.name, 'sha256': digest(target.read_bytes()),
            'archive_bytes': target.stat().st_size, 'files': len(selected), 'source_bytes': total,
            'omitted': len(omissions), 'deleted': len(deleted), 'verified': True}

if __name__ == '__main__':
    dry = '--inspect' in sys.argv
    requested = [x for x in sys.argv[1:] if not x.startswith('--')] or [*ROOTS, 'workforce']
    results = []
    for name in requested:
        args = workforce() if name == 'workforce' else candidate(name, ROOTS[name])
        results.append(archive(name, *args, dry_run=dry))
        print(json.dumps(results[-1]), flush=True)
    if not dry:
        (OUT / 'ARCHIVE-VERIFICATION.json').write_text(json.dumps(results, indent=2) + '\n', encoding='utf-8')
    print(json.dumps(results, indent=2))
