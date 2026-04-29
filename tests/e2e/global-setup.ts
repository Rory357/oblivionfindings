import { execFileSync } from 'node:child_process';
import { existsSync, renameSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Playwright global setup.
 *
 * Two responsibilities:
 *
 * 1. **Move `public/hot` aside** so the test web server uses production-built
 *    assets instead of pointing at the Vite HMR dev server. The test browser
 *    cannot reach `https://oblivionfindings.test:5173`, so leaving `public/hot`
 *    in place produces blank pages and login-form timeouts.
 *
 * 2. **Re-seed deterministic UI fixtures** so each Playwright run starts
 *    with fresh rostering and frontline attendance states. The seeders are
 *    idempotent (use upserts / forceFill), so re-running has no other side
 *    effects.
 *
 * `globalTeardown` restores `public/hot`. The seeded fixture state is left as-is
 * after the run — re-seeding happens at the next setup, not after teardown.
 */
async function globalSetup(): Promise<void> {
    const here = dirname(fileURLToPath(import.meta.url));
    const root = resolve(here, '..', '..');
    const hot = resolve(root, 'public', 'hot');
    const backup = resolve(root, 'public', '.hot.playwright.bak');

    if (existsSync(hot)) {
        renameSync(hot, backup);
        console.log(
            '[playwright global-setup] moved public/hot → public/.hot.playwright.bak so the test web server uses built assets',
        );
    }

    if (process.env.PLAYWRIGHT_SKIP_RESEED === 'true') {
        console.log(
            '[playwright global-setup] skipping rostering demo re-seed (PLAYWRIGHT_SKIP_RESEED=true)',
        );

        return;
    }

    const phpBin = resolvePhpBinary();
    if (!phpBin) {
        console.warn(
            '[playwright global-setup] could not locate the php binary — set PHP_BINARY to its absolute path. Tests may fail if rostering fixtures are stale.',
        );

        return;
    }

    try {
        // Use `shell: true` so Windows .bat shims (Herd ships php.bat) work.
        // execFileSync without shell mode cannot exec .bat files; the result
        // is an opaque "spawn ENOENT" with no useful message.
        const useShell =
            phpBin.toLowerCase().endsWith('.bat') || phpBin === 'php';
        const rosteringOutput = execFileSync(
            phpBin,
            [
                'artisan',
                'db:seed',
                '--class=RosteringProductionDemoSeeder',
                '--force',
            ],
            {
                cwd: root,
                encoding: 'utf8',
                stdio: ['ignore', 'pipe', 'pipe'],
                shell: useShell,
            },
        );
        const frontlineOutput = execFileSync(
            phpBin,
            [
                'artisan',
                'db:seed',
                '--class=FrontlineLifecycleDemoSeeder',
                '--force',
            ],
            {
                cwd: root,
                encoding: 'utf8',
                stdio: ['ignore', 'pipe', 'pipe'],
                shell: useShell,
            },
        );
        console.log(
            '[playwright global-setup] re-seeded RosteringProductionDemoSeeder and FrontlineLifecycleDemoSeeder so UI fixtures are deterministic.',
        );
        const output = [rosteringOutput, frontlineOutput]
            .map((value) => value.trim())
            .filter(Boolean)
            .join('\n');
        if (output.trim()) {
            console.log(
                `[playwright global-setup] seeder output:\n${output.trimEnd()}`,
            );
        }
    } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        const stderr =
            error && typeof error === 'object' && 'stderr' in error
                ? String((error as { stderr?: unknown }).stderr ?? '')
                : '';
        console.error(
            `[playwright global-setup] failed to re-seed rostering demo data — tests may fail if fixtures are stale.\n${message}\n${stderr}`,
        );
    }
}

/**
 * Locate the PHP binary. Prefers `PHP_BINARY` env var, falls back to common
 * Herd / system locations on Windows + Linux.
 */
function resolvePhpBinary(): string | null {
    const explicit = process.env.PHP_BINARY;
    if (explicit && existsSync(explicit)) {
        return explicit;
    }

    const candidates = [
        // Herd on Windows
        `${process.env.USERPROFILE ?? ''}\\.config\\herd\\bin\\php.bat`,
        `${process.env.USERPROFILE ?? ''}\\.config\\herd\\bin\\php.exe`,
        // Herd on macOS / Linux
        `${process.env.HOME ?? ''}/.config/herd-lite/bin/php`,
        `${process.env.HOME ?? ''}/Library/Application Support/Herd/bin/php`,
        // System PATH fallback
        'php',
    ];

    for (const candidate of candidates) {
        if (candidate && (candidate === 'php' || existsSync(candidate))) {
            return candidate;
        }
    }

    return null;
}

export default globalSetup;
