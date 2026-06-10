import { execFileSync } from 'node:child_process';
import { existsSync, renameSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { phpRequiresShell, resolvePhpBinary } from './php-binary';

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

    console.log(`[playwright global-setup] using PHP binary: ${phpBin}`);

    try {
        // resolvePhpBinary unwraps Herd's php.bat shim to the php.exe it
        // wraps so we can spawn without a shell — cmd.exe shell mode joins
        // arguments unquoted and mangles them. Shell mode remains only for
        // bare `php` (PATH lookup) or a .bat that couldn't be unwrapped,
        // which execFileSync cannot exec directly.
        const useShell = phpRequiresShell(phpBin);
        const seederClasses = [
            'RbacSeeder',
            // Module permission seeders define keys (e.g. `rostering.publish`)
            // that RbacSeeder doesn't know about. Without these, controllers
            // gating by `rostering.publish` / `governance.*` / etc. silently
            // return 403/null and Playwright specs fail with element-not-found
            // because the gated UI never renders.
            'OperationsPermissionsSeeder',
            'GovernancePermissionsSeeder',
            'SecurityDevicesPermissionsSeeder',
            'RoadmapPermissionsSeeder',
            // Map every permission row to the admin role so loginAsStaff
            // (admin@demo.test) can exercise every gated UI surface.
            'SeedAllPermissionsToAdminSeeder',
            'SystemCatalogSeeder',
            'SystemUsersSeeder',
            'SystemClientsSeeder',
            'RosteringProductionDemoSeeder',
            'FrontlineLifecycleDemoSeeder',
            'JobBoardReadinessDemoSeeder',
        ];
        const seederOutputs = seederClasses.map((seederClass) =>
            execFileSync(
                phpBin,
                ['artisan', 'db:seed', `--class=${seederClass}`, '--force'],
                {
                    cwd: root,
                    encoding: 'utf8',
                    stdio: ['ignore', 'pipe', 'pipe'],
                    shell: useShell,
                },
            ),
        );
        console.log(
            `[playwright global-setup] re-seeded ${seederClasses.join(', ')} so UI fixtures are deterministic.`,
        );
        const output = seederOutputs
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
        // artisan renders exceptions to stdout, not stderr — without stdout
        // a failed seeder reports an unactionable "Command failed: …".
        const { stdout, stderr } = (error ?? {}) as {
            stdout?: unknown;
            stderr?: unknown;
        };
        const detail = [message, stdout, stderr]
            .map((part) => String(part ?? '').trim())
            .filter(Boolean)
            .join('\n');
        console.error(
            `[playwright global-setup] failed to re-seed rostering demo data — tests may fail if fixtures are stale.\n${detail}`,
        );
    }
}

export default globalSetup;
