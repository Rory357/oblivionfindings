import { readFileSync } from 'node:fs';

import { describe, expect, it } from 'vitest';

const auditedLegacySites = {
    'alerts/index.tsx': 1,
    'assets/show.tsx': 1,
    'fuel/index.tsx': 1,
    'devices/index.tsx': 2,
    'maintenance/checklists/index.tsx': 1,
    'maintenance/schedules/index.tsx': 1,
    'resident-tracking/index.tsx': 1,
    'transports/show.tsx': 3,
    'transports/medications.tsx': 2,
} as const;

describe('Fleet workflow dialog contract', () => {
    it('removes all 13 audited direct DialogContent workflow/detail render sites', () => {
        const remaining = Object.entries(auditedLegacySites).flatMap(
            ([relativePath, auditedCount]) => {
                const source = readFileSync(
                    `resources/js/pages/fleet-assets/${relativePath}`,
                    'utf8',
                );
                const count = source.match(/<DialogContent(?:\s|>)/g)?.length ?? 0;

                expect(
                    count,
                    `${relativePath} changed from its audited ${auditedCount} legacy render site(s); update the inventory only after confirming canonical ownership`,
                ).toBeLessThanOrEqual(auditedCount);

                return Array.from({ length: count }, () => relativePath);
            },
        );

        expect(
            remaining,
            `Legacy Fleet workflow/detail dialogs remain in: ${remaining.join(', ')}`,
        ).toEqual([]);
    });
});
