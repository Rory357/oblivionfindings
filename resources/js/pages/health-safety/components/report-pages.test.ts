import { describe, expect, it } from 'vitest';

import { reportPageFor } from './report-pages';

describe('reportPageFor', () => {
    it('sends keyed-command records to their own report pages', () => {
        expect(reportPageFor('restraint')).toBe(
            '/health-safety/restraints/report?return_to=%2Fhealth-safety',
        );
        expect(reportPageFor('drill')).toBe(
            '/health-safety/drills/create?return_to=%2Fhealth-safety',
        );
        expect(reportPageFor('rtw')).toBe(
            '/health-safety/injuries/create?return_to=%2Fhealth-safety',
        );
        expect(reportPageFor('lone')).toBe('/health-safety/lone-workers?start=1');
        expect(reportPageFor('first_aid')).toBe(
            '/health-safety/first-aid/report?return_to=%2Fhealth-safety',
        );
        expect(reportPageFor('substance')).toBe('/health-safety/substances/create');
        expect(reportPageFor('exposure')).toBe('/health-safety/substances?tab=active');
        expect(reportPageFor('safeguarding')).toBe('/safeguarding/create');
    });

    it('sends people back to the page they came from when asked', () => {
        expect(reportPageFor('restraint', '/my-day')).toBe(
            '/health-safety/restraints/report?return_to=%2Fmy-day',
        );
        expect(reportPageFor('rtw', '/my-day')).toBe(
            '/health-safety/injuries/create?return_to=%2Fmy-day',
        );
        // Pages without a return keep their own address.
        expect(reportPageFor('safeguarding', '/my-day')).toBe('/safeguarding/create');
    });

    it('keeps the in-place wizards in place', () => {
        for (const key of ['incident', 'hazard', 'participation', 'toString', 'constructor']) {
            expect(reportPageFor(key)).toBeNull();
        }
    });
});
