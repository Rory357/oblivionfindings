import { realpathSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import { searchForWorkspaceRoot } from 'vite';

/**
 * `server.fs.allow` for a checkout whose packages live outside it.
 *
 * A git worktree under `.claude/worktrees/*` resolves packages from the
 * parent checkout's node_modules (by walking up, or through a junction that
 * Vite follows to its real path). That directory is outside Vite's default
 * allow root, so `?url` imports from it — the pdf.js worker — fail with
 * "Denied ID …". This allows that one node_modules directory as well.
 *
 * In a normal checkout node_modules is inside the workspace root and this
 * returns undefined, which leaves Vite's default untouched.
 */
export function nodeModulesFsAllow(root: string): string[] | undefined {
    const resolveFrom = createRequire(path.join(root, 'package.json'));
    const nodeModules = path.dirname(
        realpathSync(path.dirname(resolveFrom.resolve('vite/package.json'))),
    );
    const workspace = searchForWorkspaceRoot(root);
    const relative = path.relative(workspace, nodeModules);
    if (!relative.startsWith('..') && !path.isAbsolute(relative)) {
        return undefined;
    }

    return [workspace, nodeModules];
}
