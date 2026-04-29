import { readFileSync, writeFileSync } from 'node:fs';

import { expect, test } from '@playwright/test';

import { loginAsStaff } from './helpers';
import {
    rosteringFlagsEnabled,
    rosteringFlagSkipReason,
} from './rostering-flags';

type DashboardBaseline = {
    dashboard_p95_ms: number | Record<string, number>;
};

const baselinePath = new URL(
    './performance/rostering-dashboard-baseline.json',
    import.meta.url,
);
const baseline = JSON.parse(
    readFileSync(baselinePath, 'utf8'),
) as DashboardBaseline;
const sampleCount = 5;
const baselineEnv = process.env.PLAYWRIGHT_BASELINE_ENV ?? 'default';
const selectedBaselineMs = selectDashboardBaselineMs(baseline, baselineEnv);
const allowedP95Ms = selectedBaselineMs * 1.1;
const testTimeoutMs = Math.ceil(allowedP95Ms * sampleCount + 30_000);
const shouldUpdateBaseline =
    process.env.PLAYWRIGHT_UPDATE_ROSTERING_BASELINE === 'true';

/**
 * `PLAYWRIGHT_BASELINE_ENV` selects the performance budget tier.
 * Local php -S runs use `php_builtin` via playwright.config.ts; production-like
 * CI can keep the stricter `default` tier. To recapture the selected tier, run
 * this spec with `PLAYWRIGHT_UPDATE_ROSTERING_BASELINE=true`.
 */
function selectDashboardBaselineMs(
    data: DashboardBaseline,
    environment: string,
): number {
    if (typeof data.dashboard_p95_ms === 'number') {
        return data.dashboard_p95_ms;
    }

    const selected =
        data.dashboard_p95_ms[environment] ?? data.dashboard_p95_ms.default;

    if (selected === undefined) {
        throw new Error(
            `No rostering dashboard baseline found for "${environment}" and no default baseline is configured.`,
        );
    }

    return selected;
}

function writeDashboardBaselineMs(
    data: DashboardBaseline,
    environment: string,
    measured: number,
) {
    const rounded = Math.ceil(measured);
    const dashboardBaseline =
        typeof data.dashboard_p95_ms === 'number'
            ? { default: data.dashboard_p95_ms }
            : data.dashboard_p95_ms;

    writeFileSync(
        baselinePath,
        `${JSON.stringify(
            {
                ...data,
                dashboard_p95_ms: {
                    ...dashboardBaseline,
                    [environment]: rounded,
                },
            },
            null,
            4,
        )}\n`,
    );
}

function p95(values: number[]) {
    const sorted = [...values].sort((a, b) => a - b);
    const index = Math.ceil(sorted.length * 0.95) - 1;

    return sorted[Math.max(0, index)];
}

test.describe('operations rostering performance budget', () => {
    test.setTimeout(testTimeoutMs);
    test.skip(!rosteringFlagsEnabled, rosteringFlagSkipReason);
    test.skip(
        ({ viewport }) => !viewport || viewport.width < 1024,
        'Dashboard p95 budget is measured on desktop',
    );

    test('dashboard p95 stays within the captured budget', async ({ page }) => {
        await loginAsStaff(page);

        const durations: number[] = [];
        for (let attempt = 0; attempt < sampleCount; attempt++) {
            const started = performance.now();
            await page.goto(
                '/operations/rostering?week=2026-05-04&site_id=9001',
            );
            await page.getByTestId('rostering-publish-panel').waitFor();
            durations.push(performance.now() - started);
        }

        const measured = p95(durations);
        if (shouldUpdateBaseline) {
            writeDashboardBaselineMs(baseline, baselineEnv, measured);

            return;
        }

        expect(measured).toBeLessThanOrEqual(allowedP95Ms);
    });
});
