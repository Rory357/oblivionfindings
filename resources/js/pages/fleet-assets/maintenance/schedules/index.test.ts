import { readFileSync } from 'node:fs';

import { describe, expect, it } from 'vitest';

const source = readFileSync(
    'resources/js/pages/fleet-assets/maintenance/schedules/index.tsx',
    'utf8',
);

describe('Service schedule workflow', () => {
    it('uses an accessible asset-and-interval wizard with review', () => {
        expect(source).toContain('title="Create service schedule"');
        expect(source).toContain('description="Set a Fleet asset service interval and review it before creating the schedule."');
        expect(source).toContain('label: \'Asset & interval\'');
        expect(source).toContain('label: \'Review\'');
        expect(source).toContain('htmlFor="service-schedule-name"');
        expect(source).toContain('htmlFor="service-schedule-asset"');
        expect(source).not.toMatch(/<DialogContent(?:\s|>)/);
    });
});
