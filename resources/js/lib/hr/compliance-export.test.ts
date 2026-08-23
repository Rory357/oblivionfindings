import { describe, expect, it } from 'vitest';

import { complianceExportHref } from './compliance-export';

describe('compliance export affordance permission matrix', () => {
    it.each([
        ['overview', '/hr/compliance/export?dataset=staff'],
        ['matrix', '/hr/compliance/export?dataset=staff'],
        ['calendar', '/hr/compliance/export?dataset=renewals'],
        ['vetting', '/hr/compliance/export?dataset=vetting'],
        ['drivers', '/hr/compliance/export?dataset=drivers'],
    ] as const)(
        'maps the %s surface to its authorised dataset',
        (tab, href) => {
            expect(complianceExportHref(tab, true)).toBe(href);
        },
    );

    it.each(['overview', 'matrix', 'calendar', 'vetting', 'drivers'] as const)(
        'hides the %s export when the server decision denies it',
        (tab) => {
            expect(complianceExportHref(tab, false)).toBeNull();
        },
    );
});
