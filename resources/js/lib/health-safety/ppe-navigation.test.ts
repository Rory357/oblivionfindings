import { expect, it } from 'vitest';
import { PPE_REGISTER, ppeHref, ppeLegacyHref, ppeLocation, ppeReturn } from './ppe-navigation';

it('converges item and allocation bookmarks while retaining the exact register refinements and both paginations', () => {
    const back = '/health-safety/ppe?tab=inv_inspection&site_id=4&category=respiratory&status=maintenance&ppe_type_id=8&search=mask&page=2&allocations_page=3&view=cards';
    const href = ppeLegacyHref(back + '&item=9&section=inspections&action=inspect');
    expect(ppeLocation(href!)).toMatchObject({ kind: 'inventory', id: 9, section: 'inspections', command: 'inspect', returnTo: back });
    const allocation = ppeLegacyHref('/health-safety/ppe?tab=alloc_unack&allocation=13&section=fit-training');
    expect(ppeLocation(allocation!)).toMatchObject({ kind: 'allocations', id: 13, section: 'fit-training', returnTo: '/health-safety/ppe?tab=alloc_unack' });
});

it('returns to records without replaying actions or evidence selections and preserves one permitted origin', () => {
    const href = ppeHref('allocations', 13, { section: 'evidence', command: 'remove_evidence', attachment: 44, returnTo: '/my-day' });
    const target = new URL(ppeReturn(href), 'https://test.invalid');
    expect(target.pathname).toBe('/health-safety/ppe/allocations/13');
    expect(target.searchParams.get('section')).toBe('evidence');
    expect(target.searchParams.get('return_to')).toBe('/my-day');
    expect(target.searchParams.has('action')).toBe(false); expect(target.searchParams.has('attachment')).toBe(false);
    expect(ppeReturn('/sites/4?tab=ppe&action=allocate&attachment=44')).toBe('/sites/4?tab=ppe');
});

it('retains the canonical item when moving from its inspection or allocation and restores its register origin', () => {
    const origin = ppeHref('inventory', 9, { section: 'inspections', returnTo: '/health-safety/ppe?site_id=4&page=2' });
    const child = ppeHref('inspections', 25, { section: 'evidence', returnTo: origin });
    expect(ppeLocation(child).returnTo).toBe(origin);
    expect(ppeReturn(child)).toBe('/health-safety/ppe/inspections/25?section=evidence');
    expect(ppeHref('inventory', 9, { returnTo: origin })).toBe('/health-safety/ppe/inventory/9');
});

it('rejects malformed, normalized, external, oversized and deeply nested return destinations', () => {
    for (const value of ['https://outside.test/', '//outside.test/', '/health-safety/ppe/inventory/0', '/health-safety/ppe/inventory/9007199254740992', '/health-safety/ppe/../ppe/inventory/9', '/health-safety/ppe#history', '/health-safety/ppe?search=' + 'é'.repeat(800), '/sites/9007199254740992?tab=ppe']) expect(ppeReturn(value)).toBe(PPE_REGISTER);
    const deep = '/health-safety/ppe/inventory/9?return_to=' + encodeURIComponent('/health-safety/ppe/allocations/13?return_to=%2Fmy-day');
    expect(ppeReturn(deep)).toBe('/health-safety/ppe/inventory/9');
    expect(ppeReturn('/health-safety/ppe/inventory/9?return_to=%2Fhealth-safety%2Fppe%2Finventory%2F9')).toBe('/health-safety/ppe/inventory/9');
});

it('does not pick actions, children or filters from ambiguous query values', () => {
    expect(ppeLocation('/health-safety/ppe/inventory/9?action=dispose&action=edit&section=history&section=evidence&attachment=0')).toMatchObject({ section: 'overview', command: null, attachment: null });
    expect(ppeReturn('/health-safety/ppe?site_id=4&site_id=8&status=unknown&page=0&tab=types&search=mask')).toBe('/health-safety/ppe?tab=types&search=mask');
    expect(ppeLegacyHref('/health-safety/ppe?item=9&allocation=13')).toBeNull();
    expect(ppeLegacyHref('/health-safety/ppe?item=9&item=10')).toBeNull();
    expect(ppeHref('inventory', Number.NaN)).toBe(PPE_REGISTER);
});

it('normalizes sections to the actual record purpose without creating mutation authority', () => {
    expect(ppeLocation('/health-safety/ppe/inventory/9?section=fit-training').section).toBe('allocations');
    expect(ppeLocation('/health-safety/ppe/allocations/13?section=inspections').section).toBe('overview');
    expect(ppeLocation('/health-safety/ppe/inspections/25?section=fit-training').section).toBe('overview');
    expect(ppeLocation('/health-safety/ppe/inventory/9?action=approve_training').command).toBeNull();
});

it('preserves independent loaded child pages and drops malformed or duplicated page values', () => {
    const href = ppeHref('inventory', 9, { section: 'inspections', pages: { allocations_page: 3, inspections_page: 2, attachments_page: 5, history_page: 4 }, returnTo: '/health-safety/ppe?page=7' });
    expect(ppeLocation(href).pages).toEqual({ allocations_page: 3, inspections_page: 2, attachments_page: 5, history_page: 4 });
    expect(ppeReturn(href)).toBe(href);
    expect(ppeLocation('/health-safety/ppe/inventory/9?history_page=1&history_page=2&attachments_page=0&inspections_page=9007199254740992').pages).toEqual({});
    const child = ppeHref('inspections', 25, { section: 'evidence', returnTo: href });
    expect(ppeLocation(child).returnTo).toBe(href);
});
