import { describe, expect, it } from 'vitest';
import { safetyReviewReturn } from './navigation';
import {
    RISK_REGISTER,
    riskHref,
    riskLocation,
    riskReturn,
} from './risk-navigation';
describe('risk workspace return context', () => {
    it('retains register facets without restoring legacy modal commands', () => {
        const context = riskReturn(
            RISK_REGISTER +
                '?tab=due&site_id=2&page=3&risk_acceptable=0&search=hoist&assessment=8&action=archive',
        );
        expect(
            new URL(context, 'https://test.invalid').searchParams.get('page'),
        ).toBe('3');
        expect(context).not.toContain('assessment=');
        expect(
            riskLocation(
                riskHref(8, { section: 'history', returnTo: context }),
            ),
        ).toEqual({ section: 'history', command: null, returnTo: context });
    });
    it.each([
        'https://evil.test',
        '//evil.test/x',
        '/\\evil.test',
        '/sites/0',
        '/sites/2#bad',
        '/sites/2\n',
        '/health-safety/risk-assessments/8',
    ])('rejects unsafe or recursive leaf %s', (value) =>
        expect(riskReturn(value)).toBe(RISK_REGISTER),
    );
    it('retains Site and Client tabs, removes nested redirect attempts', () => {
        expect(
            riskReturn('/sites/2?tab=risk_assessments&return_to=%2Fsites%2F3'),
        ).toBe('/sites/2?tab=risk_assessments');
        expect(riskReturn('/operations/clients/7?tab=risk_management')).toBe(
            '/operations/clients/7?tab=risk_management',
        );
    });
    it('carries one bounded risk return through linked safety work', () => {
        const sanitized = safetyReviewReturn(
            riskHref(8, {
                section: 'connections',
                returnTo: '/sites/2?tab=risk_assessments',
            }),
        );
        expect(
            new URL(sanitized, 'https://test.invalid').searchParams.get(
                'return_to',
            ),
        ).toBe('/sites/2?tab=risk_assessments');
        expect(sanitized).toContain('section=connections');
    });
    it('accepts only known sections and inline commands', () => {
        expect(
            riskLocation(
                '/health-safety/risk-assessments/2?section=secret&action=delete',
            ),
        ).toMatchObject({ section: 'overview', command: null });
        expect(
            riskLocation(riskHref(2, { command: 'complete-review' })).command,
        ).toBe('complete-review');
    });
});
