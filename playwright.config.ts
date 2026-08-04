import { defineConfig, devices } from '@playwright/test';

const port = Number(process.env.PLAYWRIGHT_PORT ?? 4173);
const baselineEnv =
    process.env.PLAYWRIGHT_BASELINE_ENV ??
    (process.env.CI ? 'default' : 'php_builtin');

process.env.PLAYWRIGHT_BASELINE_ENV = baselineEnv;
process.env.FEATURE_ROSTERING_PUBLISH ??= 'true';
process.env.FEATURE_ROSTERING_AUTO_SCHEDULE ??= 'true';

const webServerEnv = Object.fromEntries(
    Object.entries(process.env).filter(
        (entry): entry is [string, string] => typeof entry[1] === 'string',
    ),
);

export default defineConfig({
    testDir: './tests',
    testMatch: /.*\.spec\.ts/,
    globalSetup: './tests/e2e/global-setup.ts',
    globalTeardown: './tests/e2e/global-teardown.ts',
    timeout: 30_000,
    expect: {
        timeout: 10_000,
        toHaveScreenshot: {
            animations: 'disabled',
            maxDiffPixelRatio: 0.01,
        },
    },
    fullyParallel: false,
    workers: 1,
    reporter: [
        ['list'],
        ['html', { open: 'never', outputFolder: 'playwright-report' }],
    ],
    snapshotPathTemplate: '{testDir}/__screenshots__/{projectName}/{arg}{ext}',
    use: {
        baseURL: `http://127.0.0.1:${port}`,
        testIdAttribute: 'data-test',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'chromium-desktop',
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1440, height: 900 },
            },
        },
        {
            name: 'chromium-mobile',
            use: { ...devices['Pixel 7'] },
        },
    ],
    webServer: {
        // server.php at the repo root returns `false` for real static files so
        // PHP's built-in server applies correct MIME types (CSS / JS / images).
        // Without it, `-t public public/index.php` routes every request through
        // Laravel and serves the Inertia shell HTML for `/build/assets/*.css`,
        // which breaks asset loading and produces blank pages in tests.
        command: `php -S 127.0.0.1:${port} -t public server.php`,
        env: webServerEnv,
        url: `http://127.0.0.1:${port}`,
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
    },
});
