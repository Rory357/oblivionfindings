import { describe, expect, it } from 'vitest';
import { CHEMICAL_EXPOSURES, exposureCaptureHref, exposureHref, exposureLocation, exposureReturn } from './chemical-exposure-navigation';
const query = (value: string) => new URL(value, 'https://example.invalid').searchParams;
describe('private exposure permanent navigation', () => {
    it('preserves actual private source filters but excludes receipt/body/duplicate and invalid time selectors', () => {
        const back = exposureReturn(CHEMICAL_EXPOSURES + '?site_id=3&worker_id=7&substance_id=9&page=2&from=2026-09-03T12%3A00%3A00%2B12%3A00&to=2026-09-19T00%3A00%3A00Z&receipt_key=private&symptoms=private&return_to=%2Ftasks');
        expect([...query(back).keys()]).toEqual(['site_id', 'worker_id', 'substance_id', 'page', 'from', 'to']);
        expect(query(back).get('from')).toBe('2026-09-03T12:00:00+12:00');
        expect(exposureReturn(CHEMICAL_EXPOSURES + '?site_id=3&site_id=4&worker_id=2147483648&from=2026-02-30T12%3A00%3A00Z&to=2026-09-19T00%3A00%3A00%2B14%3A01')).toBe(CHEMICAL_EXPOSURES);
    });
    it('returns to exact public chemical register filters or a read-only source section', () => {
        const capture = exposureCaptureHref(9, '/health-safety/substances?tab=sds_expiring&site_id=3&q=cleaning&period=90&page=2&action=edit&receipt_key=private');
        const back = query(capture).get('return_to')!;
        expect(query(back).get('tab')).toBe('sds_expiring'); expect(query(back).get('site_id')).toBe('3'); expect(query(back).get('page')).toBe('2');
        expect(query(back).has('receipt_key')).toBe(false);
        expect(exposureReturn('/health-safety/substances/9?section=exposures&action=record_exposure&return_to=%2Ftasks')).toBe('/health-safety/substances/9?section=exposures');
    });
    it('preserves read paging across connected leaves while withholding executable selectors from Back', () => {
        const href = exposureHref(22, { section: 'followups', action: 'complete_followup', followup: 11, pages: { followups_page: 3 }, returnTo: '/incidents?kind=incidents&site_id=3&from=2026-09-03&to=2026-09-19' });
        expect(exposureLocation(href)).toMatchObject({ section: 'followups', action: 'complete_followup', followup: 11, evidence: null, pages: { followups_page: 3 } });
        expect(query(query(href).get('return_to')!).get('kind')).toBe('incidents');
        expect(exposureReturn(href)).toBe(CHEMICAL_EXPOSURES + '/22?section=followups&followups_page=3');
        expect(exposureHref(22, { returnTo: href })).toBe(CHEMICAL_EXPOSURES + '/22');
    });
    it('rejects foreign, normalized, malformed and ambiguous navigation without creating an access grant', () => {
        for (const value of ['//foreign.invalid', 'https://foreign.invalid', CHEMICAL_EXPOSURES + '#private', CHEMICAL_EXPOSURES + '?page=%zz',
            CHEMICAL_EXPOSURES + '/22/../', CHEMICAL_EXPOSURES + '?worker_id[]=7', CHEMICAL_EXPOSURES + '?q=%0Aprivate']) expect(exposureReturn(value)).toBe(CHEMICAL_EXPOSURES);
        expect(exposureHref(2147483648)).toBe(CHEMICAL_EXPOSURES);
        expect(exposureCaptureHref(-1)).toBe('/health-safety/substances');
        expect(exposureLocation(CHEMICAL_EXPOSURES + '/22?section=medical&action=remove_evidence&action=correct&evidence=1&evidence=2')).toMatchObject({ section: 'overview', action: null, evidence: null });
    });
    it('keeps a transfer bookmark bound to the actual child but removes it from read-only Back', () => {
        const href = exposureHref(22, { section: 'followups', action: 'reassign_followup', followup: 31, pages: { followups_page: 2 } });
        expect(exposureLocation(href)).toMatchObject({ section: 'followups', action: 'reassign_followup', followup: 31 });
        expect(exposureReturn(href)).toBe('/health-safety/chemical-exposures/22?section=followups&followups_page=2');
        expect(exposureLocation(href + '&followup=32').followup).toBeNull();
    });
});
