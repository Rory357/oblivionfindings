import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, renameSync, unlinkSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { phpRequiresShell, resolvePhpBinary } from '../php-binary';
import { GOVERNANCE_DISPOSABLE_DB_PREFIX } from './global-setup';

export default async function globalTeardown(): Promise<void> {
    const here = dirname(fileURLToPath(import.meta.url));
    const root = resolve(here, '..', '..', '..');
    const hot = resolve(root, 'public', 'hot');
    const backup = resolve(root, 'public', '.hot.playwright.bak');

    if (existsSync(backup) && !existsSync(hot)) {
        renameSync(backup, hot);
        console.log('[governance playwright teardown] restored public/hot');
    }

    const stateFile = resolve(here, '.governance-e2e-db.json');
    if (!existsSync(stateFile)) {
        return;
    }

    try {
        const { dbName } = JSON.parse(readFileSync(stateFile, 'utf8'));
        if (dbName && typeof dbName === 'string' && dbName.startsWith(GOVERNANCE_DISPOSABLE_DB_PREFIX)) {
            console.log(`[governance playwright teardown] dropping disposable database ${dbName}...`);
            const phpBin = resolvePhpBinary() ?? 'php';
            execFileSync(
                phpBin,
                [
                    '-r',
                    `
                $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'testUser', 'test101');
                $pdo->exec('DROP DATABASE IF EXISTS \`${dbName}\`');
            `,
                ],
                {
                    cwd: root,
                    shell: phpRequiresShell(phpBin),
                },
            );
            console.log(`[governance playwright teardown] dropped ${dbName}.`);
        }
    } catch (e) {
        console.warn('[governance playwright teardown] failed to drop database:', e);
    } finally {
        if (existsSync(stateFile)) {
            unlinkSync(stateFile);
        }
    }
}
