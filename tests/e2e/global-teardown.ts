import { existsSync, renameSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Playwright global teardown — restore `public/hot` if `globalSetup` moved it.
 */
async function globalTeardown(): Promise<void> {
    const here = dirname(fileURLToPath(import.meta.url));
    const root = resolve(here, '..', '..');
    const hot = resolve(root, 'public', 'hot');
    const backup = resolve(root, 'public', '.hot.playwright.bak');

    if (existsSync(backup) && !existsSync(hot)) {
        renameSync(backup, hot);
        console.log(
            '[playwright global-teardown] restored public/hot from public/.hot.playwright.bak',
        );
    }
}

export default globalTeardown;
