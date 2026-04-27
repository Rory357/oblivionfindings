import { defineConfig, devices } from '@playwright/test';

const port = Number(process.env.PLAYWRIGHT_PORT ?? 4173);

export default defineConfig({
    testDir: './tests',
    testMatch: /.*\.spec\.ts/,
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
                viewport: { width: 1440, height: 1000 },
            },
        },
        {
            name: 'chromium-mobile',
            use: { ...devices['Pixel 7'] },
        },
    ],
    webServer: {
        command: `php -S 127.0.0.1:${port} -t public public/index.php`,
        url: `http://127.0.0.1:${port}`,
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
    },
});
