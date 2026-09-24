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
    // PKG-01 (2026-09-20) rebuilt the work queue on the Event Horizon header
    // with a Table/Cards toggle. Fleet is desktop web-only (the 2026-07-13
    // fleet audit set no mobile-card requirement) and DESIGN.md scrolls wide
    // tables inside their own container, so the queue declares horizontal
    // scroll instead of taking the worker mobile-card branch.
    'maintenance/work-orders/index.tsx',
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
        expect(workerPages).toHaveLength(12);
        expect(managerPages).toHaveLength(13);

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
        // The Event Horizon page top (PageHeader / PageLayout from
        // @/components/page; DESIGN.md "Page headers", approved 2026-09-05)
        // is the current shared hero: PKG-01 work orders and the PKG-02B
        // vehicle profile use it.
        const outliers = pageFiles(root)
            .filter((file) => readFileSync(file, 'utf8').includes('<Head'))
            .filter((file) => {
                const page = readFileSync(file, 'utf8');
                return !/HeroShell|FleetCompactHero|data-fleet-mobile-hero|<PageHeader[\s>]|<PageLayout[\s>]/.test(
                    page,
                );
            });

        expect(outliers).toEqual([]);
    });
});
