import { readFileSync } from 'node:fs';

import { describe, expect, it } from 'vitest';

const source = readFileSync(
    'resources/js/pages/fleet-assets/resident-tracking/index.tsx',
    'utf8',
);

describe('Assign tracker workflow', () => {
    it('uses a labelled consent-aware wizard with review', () => {
        expect(source).toContain('title="Assign tracker to resident"');
        expect(source).toContain('label: \'Resident & device\'');
        expect(source).toContain('label: \'Consent check\'');
        expect(source).toContain('label: \'Review\'');
        expect(source).toContain('htmlFor="assign-tracker-resident"');
        expect(source).toContain('htmlFor="assign-tracker-device"');
        expect(source).toContain('id="assign-tracker-consent"');
        expect(source).not.toMatch(/<DialogContent(?:\s|>)/);
    });
});
