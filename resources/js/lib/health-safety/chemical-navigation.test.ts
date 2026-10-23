import { describe, expect, it } from 'vitest';
import { CHEMICAL_REGISTER, chemicalHref, chemicalLocation, chemicalReturn } from './chemical-navigation';
describe('chemical permanent records and bounded returns', () => {
    it('keeps real source filters but strips command, receipt, private and duplicate selectors', () => {
        const back = chemicalReturn('/health-safety/substances?tab=sds_expiring&site_id=3&q=cleaning&physical_form=liquid&period=90&page=2&action=record_exposure&substance=9&receipt_key=private&return_to=%2Ftasks');
        expect(new URL(back, 'https://example.invalid').searchParams).toEqual(new URLSearchParams('tab=sds_expiring&physical_form=liquid&site_id=3&page=2&period=90&q=cleaning'));
        expect(chemicalReturn('/health-safety/substances?tab=active&tab=inactive&site_id=2147483648&controlled=yes')).toBe(CHEMICAL_REGISTER);
    });
    it('preserves incident kind and selected Site/period on a connected leaf and prevents nested return loops', () => {
        const back = chemicalReturn('/incidents?kind=incidents&site_id=3&from=2026-09-03&to=2026-09-19&return_to=%2Fhealth-safety%2Fsubstances');
        const query = new URL(back, 'https://example.invalid').searchParams;
        expect(query.get('kind')).toBe('incidents'); expect(query.get('site_id')).toBe('3'); expect(query.get('from')).toBe('2026-09-03'); expect(query.has('return_to')).toBe(false);
        for (const value of ['//external.invalid', '/health-safety/substances#private', '/health-safety/substances?q=%0Asecret', 'https://external.invalid']) expect(chemicalReturn(value)).toBe(CHEMICAL_REGISTER);
    });
    it('keeps each public child bookmark on its permanent source without turning it into authority', () => {
        const href = chemicalHref(9, { section: 'storage', action: 'restore_storage', storage: 12, returnTo: '/my-day' });
        expect(chemicalLocation(href)).toEqual({ section: 'storage', action: 'restore_storage', storage: 12, sds: null });
        expect(chemicalLocation('/health-safety/substances/9?section=private&action=record_exposure&storage=12&storage=13')).toEqual({ section: 'overview', action: null, storage: null, sds: null });
    });
    it('returns from a public chemical to the exact private read section or filters without executable or clinical state', () => {
        const href = chemicalHref(9, { section: 'controls', returnTo: '/health-safety/chemical-exposures/22?section=connections&history_page=2&action=correct&receipt_key=private&symptoms=private&return_to=%2Ftasks' });
        const back = new URL(href, 'https://example.invalid').searchParams.get('return_to');
        expect(back).toBe('/health-safety/chemical-exposures/22?section=connections&history_page=2');
        expect(chemicalReturn('/health-safety/chemical-exposures?worker_id=7&substance_id=9&site_id=3&page=2&command=complete_followup')).toBe('/health-safety/chemical-exposures?site_id=3&worker_id=7&substance_id=9&page=2');
        for (const bad of ['/health-safety/chemical-exposures/2147483648', '/health-safety/chemical-exposures/capture?substance_id=9',
            '/health-safety/chemical-exposures/22?worker_id[]=7', '/health-safety/chemical-exposures/22?section=connections&notes=%0Asecret']) expect(chemicalReturn(bad)).toBe(CHEMICAL_REGISTER);
    });
});
