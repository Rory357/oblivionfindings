import { readFileSync } from 'node:fs';

import { expect, test } from '@playwright/test';

import { loginAsStaff } from './helpers';
import {
    rosteringFlagsEnabled,
    rosteringFlagSkipReason,
} from './rostering-flags';

type DashboardBaseline = {
    dashboard_p95_ms: number;
};

const baseline = JSON.parse(
    readFileSync(
        new URL(
            './performance/rostering-dashboard-baseline.json',
            import.meta.url,
        ),
        'utf8',
    ),
) as DashboardBaseline;
const sampleCount = 5;
const allowedP95Ms = baseline.dashboard_p95_ms * 1.1;
const testTimeoutMs = Math.ceil(allowedP95Ms * sampleCount + 30_000);

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
        expect(measured).toBeLessThanOrEqual(allowedP95Ms);
    });
});
