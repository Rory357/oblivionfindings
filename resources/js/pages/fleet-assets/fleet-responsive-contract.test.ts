import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

const root = 'resources/js/pages/fleet-assets';

const workerPages = [
    'alerts/index.tsx',
    'keys/index.tsx',
    'fuel/index.tsx',
    'mileage/index.tsx',
    'handovers/index.tsx',
    'inspections/index.tsx',
    'outings/index.tsx',
    'transports/index.tsx',
    'transports/medications.tsx',
    'incidents/index.tsx',
    'resident-tracking/index.tsx',
    'maintenance/schedules/index.tsx',
    'maintenance/work-orders/index.tsx',
] as const;

const managerPages = [
    'compliance/index.tsx',
    'dashboard.tsx',
    'trips/index.tsx',
    'assets/show.tsx',
    'devices/index.tsx',
    'drivers/index.tsx',
    'drivers/show.tsx',
    'reports/index.tsx',
    'reports/by-house.tsx',
    'reports/reimbursement.tsx',
    'reports/cost-allocation.tsx',
    'reports/community-access.tsx',
] as const;

function source(relativePath: string): string {
    return readFileSync(`${root}/${relativePath}`, 'utf8');
}

function pageFiles(directory: string): string[] {
    return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const path = join(directory, entry.name);
        if (entry.isDirectory()) return pageFiles(path);
        return entry.name.endsWith('.tsx') && !entry.name.endsWith('.test.tsx')
            ? [path]
            : [];
    });
}

describe('Fleet responsive and hero contracts', () => {
    it('gives all 25 audited list/report pages an intentional narrow strategy', () => {
        expect(workerPages).toHaveLength(13);
        expect(managerPages).toHaveLength(12);

        for (const relativePath of workerPages) {
            const page = source(relativePath);
            if (/<(?:table|Table)(?:\s|>)/.test(page)) {
                expect(
                    page,
                    `${relativePath} needs the shared mobile-card/desktop-table branch`,
                ).toContain('<FleetResponsiveTable');
                for (const marker of ['identity', 'status', 'action', 'time']) {
                    expect(
                        page,
                        `${relativePath} mobile rows need a ${marker} field`,
                    ).toContain(`data-fleet-row-${marker}`);
                }
            } else {
                expect(
                    page,
                    `${relativePath} needs an explicit mobile list branch`,
                ).toContain('data-fleet-mobile-list');
            }
        }

        for (const relativePath of managerPages) {
            expect(
                source(relativePath),
                `${relativePath} needs a declared narrow strategy`,
            ).toContain('data-fleet-narrow-strategy="horizontal-scroll"');
        }
    });

    it('keeps every titled Fleet page in the shared hero family', () => {
        const outliers = pageFiles(root)
            .filter((file) => readFileSync(file, 'utf8').includes('<Head'))
            .filter((file) => {
                const page = readFileSync(file, 'utf8');
                return !/HeroShell|FleetCompactHero|data-fleet-mobile-hero/.test(
                    page,
                );
            });

        expect(outliers).toEqual([]);
    });
});
