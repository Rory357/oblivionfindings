import { defineConfig, devices } from '@playwright/test';
import { resolvePhpBinary } from './tests/e2e/php-binary';

const port = Number(process.env.PLAYWRIGHT_PORT ?? 4173);
const phpBin = resolvePhpBinary() ?? 'php';
const baselineEnv =
    process.env.PLAYWRIGHT_BASELINE_ENV ??
    (process.env.CI ? 'default' : 'php_builtin');

process.env.PLAYWRIGHT_BASELINE_ENV = baselineEnv;
process.env.CACHE_STORE = 'database';

const webServerEnv = Object.fromEntries(
    Object.entries(process.env).filter(
        (entry): entry is [string, string] => typeof entry[1] === 'string',
    ),
);

export default defineConfig({
    testDir: './tests/e2e/governance',
    testMatch: /.*\.spec\.ts/,
    globalSetup: './tests/e2e/governance/global-setup.ts',
    globalTeardown: './tests/e2e/governance/global-teardown.ts',
    timeout: 60_000,
    expect: {
        timeout: 15_000,
    },
    fullyParallel: false,
    workers: 1,
    reporter: [
        ['list'],
        ['html', { open: 'never', outputFolder: 'playwright-report-governance' }],
    ],
    use: {
        baseURL: `http://127.0.0.1:${port}`,
        testIdAttribute: 'data-test',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'governance-desktop-1366',
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1366, height: 768 },
            },
        },
        {
            name: 'governance-desktop-1920',
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1920, height: 1080 },
            },
        },
    ],
    webServer: {
        command: `"${phpBin}" -S 127.0.0.1:${port} -t public tests/e2e/governance/server.php`,
        env: webServerEnv,
        url: `http://127.0.0.1:${port}/_health`,
        reuseExistingServer: false,
        timeout: 120_000,
    },
});
