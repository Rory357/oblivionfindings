import { expect, it } from 'vitest';
import { injuryHref, injuryLegacyHref, injuryLocation, injuryReturn } from './injury-navigation';

it('converges a legacy recovery bookmark while retaining its filtered register', () => {
    const origin = '/health-safety/injuries?tab=return_to_work&site_id=4&period=year&from=2026-10-01&q=review&page=2';
    const href = injuryLegacyHref(origin + '&injury=9&section=rtw&action=add_rtw&plan=3');
    expect(href).not.toBeNull();
    const location = injuryLocation(href!);
    expect(location).toMatchObject({
        section: 'recovery', command: 'add_rtw', plan: 3,
    });
    expect(Object.fromEntries(new URL(location.returnTo, 'https://test.invalid').searchParams)).toEqual({
        tab: 'return_to_work', site_id: '4', period: 'year', from: '2026-10-01', q: 'review', page: '2',
    });
    const back = new URL(injuryReturn(href), 'https://test.invalid');
    expect(back.pathname).toBe('/health-safety/injuries/9');
    expect(back.searchParams.get('section')).toBe('recovery');
    expect(back.searchParams.get('plan')).toBe('3');
    expect(back.searchParams.has('action')).toBe(false);
});

it('keeps the exact source and one supported origin, without replaying evidence or lifecycle commands', () => {
    const origin = '/tasks?assigned=me';
    const href = injuryHref(9, {
        section: 'evidence', command: 'close', attachment: 2, returnTo: origin,
    });
    const back = new URL(injuryReturn(href), 'https://test.invalid');
    expect(back.pathname).toBe('/health-safety/injuries/9');
    expect(back.searchParams.get('section')).toBe('evidence');
    expect(back.searchParams.has('attachment')).toBe(false);
    expect(back.searchParams.has('action')).toBe(false);
    expect(back.searchParams.get('return_to')).toBe(origin);
    expect(injuryReturn('/hr/wellbeing?action=close&return_to=/settings')).toBe('/hr/wellbeing');
});

it('retains false refinements, valid NZ calendar dates and all existing register filters', () => {
    const target = '/health-safety/injuries?tab=all&status=reported&severity=serious&treatment=gp_visit&acc_open=false&worksafe=0&period=year&from=2026-10-01&to=2026-10-31&type=manual_handling&body_part=left+arm';
    const query = new URL(injuryReturn(target), 'https://test.invalid').searchParams;
    expect(query.get('acc_open')).toBe('false');
    expect(query.get('worksafe')).toBe('0');
    expect(query.get('body_part')).toBe('left arm');
    expect(query.get('from')).toBe('2026-10-01');
    expect(query.get('treatment')).toBe('gp_visit');
    expect(injuryReturn('/health-safety/injuries?from=2026-02-30&to=tomorrow&page=0&severity=unknown&site_id=1&site_id=2')).toBe('/health-safety/injuries');
});

it('rejects outside, normalized, oversized, cyclic and deeply nested return hints', () => {
    for (const value of [
        'https://outside.test/', '//outside.test/', '/health-safety/injuries/../injuries/9',
        '/health-safety/injuries/0', '/health-safety/injuries/9007199254740992',
        '/health-safety/injuries?q=' + 'é'.repeat(800), '/health-safety/injuries/9#evidence',
    ]) expect(injuryReturn(value)).toBe('/health-safety/injuries');
    expect(injuryHref(9, { returnTo: '/health-safety/injuries/9?return_to=%2Ftasks' })).toBe('/health-safety/injuries/9');
    const deeper = '/incidents/3?return_to=' + encodeURIComponent('/health-safety/injuries/4?return_to=%2Ftasks');
    expect(injuryReturn(deeper)).toBe('/incidents/3');
    expect(injuryLegacyHref('/health-safety/injuries?injury=4&injury=5')).toBeNull();
});

it('does not select a command or child from ambiguous or invalid bookmarks', () => {
    expect(injuryLocation('/health-safety/injuries/9?action=close&action=edit&section=capacity&section=history&plan=-1&assessment=9007199254740992&attachment=0')).toMatchObject({
        section: 'overview', command: null, plan: null, assessment: null, attachment: null,
    });
    expect(injuryLegacyHref('/health-safety/injuries?injury=8&action=unknown&section=unknown')).toBe('/health-safety/injuries/8?section=overview&return_to=%2Fhealth-safety%2Finjuries');
    expect(injuryHref(Number.NaN)).toBe('/health-safety/injuries');
});

it('returns to the actual HR injury tab with one permitted origin and no private selection', () => {
    const profile = '/hr/people/12?tab=injuries';
    const href = injuryHref(9, { section: 'capacity', returnTo: profile });
    expect(injuryLocation(href).returnTo).toBe(profile);
    expect(injuryReturn(profile + '&action=close&assessment=4&attachment=2')).toBe(profile);
    expect(injuryReturn('/hr/people/12?tab=injuries&tab=training')).toBe('/hr/people/12');
    expect(injuryReturn('/hr/people/9007199254740992?tab=injuries')).toBe('/health-safety/injuries');
    expect(injuryReturn(profile + '&return_to=' + encodeURIComponent('/tasks?assigned=me')))
        .toBe(profile + '&return_to=' + encodeURIComponent('/tasks?assigned=me'));
});
