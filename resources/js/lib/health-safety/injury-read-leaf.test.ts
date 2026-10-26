import { expect, it } from 'vitest';
import { firstAidReturn } from './first-aid-navigation';
import { injuryHref, injuryReturn } from './injury-navigation';
import { injuryReadLeaf } from './injury-read-leaf';
import { restraintReturn } from './restraint-navigation';
import { safetyReviewReturn } from './navigation';
import { incidentRegisterReturn } from '@/lib/incidents/navigation';

it('keeps the same canonical record and stripped command fields across both source return helpers', () => {
    const input = '/health-safety/injuries/9?section=rtw&plan=3&action=edit_rtw&attachment=4&receipt_key=private';
    const leaf = '/health-safety/injuries/9?section=recovery&plan=3';
    expect(injuryReadLeaf(input)).toBe(leaf); expect(injuryReturn(input)).toBe(leaf);
    expect(firstAidReturn(input)).toBe(leaf); expect(restraintReturn(input)).toBe(leaf);
    expect(safetyReviewReturn(input)).toBe(leaf); expect(incidentRegisterReturn(input)).toBe(leaf);
});
it('retains all meaningful injury register refinements across reciprocal returns', () => {
    const input = '/health-safety/injuries?tab=return_to_work&q=0&site_id=4&status=reported&severity=serious&treatment=gp_visit&acc_open=0&worksafe=false&period=year&from=2026-10-01&to=2026-10-31&type=strain&body_part=left+arm&page=2';
    const leaf = injuryReadLeaf(input);
    expect(leaf).toBe(injuryReturn(input)); expect(firstAidReturn(input)).toBe(leaf); expect(restraintReturn(input)).toBe(leaf);
});
it('returns from a connected source to the injury and one task origin without replaying a command', () => {
    const injury = injuryHref(9, { section: 'connections', command: 'close', returnTo: '/tasks?assigned=me' });
    const source = '/health-safety/first-aid/3?section=connections&return_to=' + encodeURIComponent('/health-safety/injuries/9?section=connections&action=close');
    const result = firstAidReturn(source);
    const back = new URL(result, 'https://test.invalid').searchParams.get('return_to');
    expect(back).toBe('/health-safety/injuries/9?section=connections');
    expect(firstAidReturn(injury)).toContain('return_to=%2Ftasks%3Fassigned%3Dme');
});
it('rejects unsafe identities, outside or normalized paths and a second origin in the literal leaf', () => {
    for (const input of ['/health-safety/injuries/0', '/health-safety/injuries/9007199254740992', '/health-safety/injuries/../injuries/9', '//outside.test/health-safety/injuries/9', '/health-safety/injuries/9?return_to=%2Ftasks', '/health-safety/injuries?q=' + 'é'.repeat(800)]) expect(injuryReadLeaf(input)).toBeNull();
    const cyclic = '/health-safety/first-aid/3?return_to=' + encodeURIComponent('/health-safety/injuries/9?return_to=%2Fhealth-safety%2Ffirst-aid%2F3');
    expect(new URL(firstAidReturn(cyclic), 'https://test.invalid').searchParams.has('return_to')).toBe(false);
});
