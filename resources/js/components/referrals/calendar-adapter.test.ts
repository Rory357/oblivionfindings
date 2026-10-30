import { afterEach, expect, it, vi } from 'vitest';
import {
    referralCalendarAdapter,
    referralFollowUpSeed,
} from './calendar-adapter';

vi.mock('@inertiajs/react', () => ({ router: { visit: vi.fn() } }));
afterEach(() => vi.unstubAllGlobals());
const range = {
    start: new Date('2026-10-01T00:00:00Z'),
    end: new Date('2026-11-01T00:00:00Z'),
};

it('offers a future default without changing an explicitly selected time or past date', () => {
    const now = new Date(2026, 9, 16, 23, 48);
    expect(referralFollowUpSeed({ date: now }, now).getTime()).toBeGreaterThan(
        now.getTime(),
    );
    expect(referralFollowUpSeed({ date: now, hour: 10.5 }, now)).toEqual(
        new Date(2026, 9, 16, 10, 30),
    );
    expect(referralFollowUpSeed({ date: new Date(2026, 9, 15) }, now)).toEqual(
        new Date(2026, 9, 15, 9),
    );
    expect(referralFollowUpSeed({ date: new Date(2026, 9, 17) }, now)).toEqual(
        new Date(2026, 9, 17, 9),
    );
});

it('loads every calendar page and de-duplicates overlapping responses', async () => {
    const fetch = vi
        .fn()
        .mockResolvedValueOnce({
            ok: true,
            json: async () => ({ events: [{ id: 'one' }], next_page: 2 }),
        })
        .mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                events: [{ id: 'one' }, { id: 'two' }],
                next_page: null,
            }),
        });
    vi.stubGlobal('fetch', fetch);
    const result = await referralCalendarAdapter({}, vi.fn()).loadItems(range);
    expect(result.events.map((event) => event.id)).toEqual(['one', 'two']);
    expect(fetch.mock.calls[1][0]).toContain('page=2');
    expect(fetch.mock.calls[0][1]).toMatchObject({ cache: 'no-store' });
});

it('rejects the entire load when access changes during pagination', async () => {
    vi.stubGlobal(
        'fetch',
        vi
            .fn()
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    events: [{ id: 'earlier-private-work' }],
                    next_page: 2,
                }),
            })
            .mockResolvedValueOnce({ ok: false, status: 403 }),
    );
    await expect(
        referralCalendarAdapter({}, vi.fn()).loadItems(range),
    ).rejects.toMatchObject({ status: 403 });
});

it('does not offer subscriptions or export and keeps unavailable audience filters absent', () => {
    const adapter = referralCalendarAdapter({}, vi.fn());
    expect(adapter.allowExport).toBe(false);
    expect(adapter.allowSubscriptions).toBe(false);
    expect(adapter.showCompletionMeter).toBe(false);
    // Copy names the zone plainly and entries open the shared in-calendar detail.
    expect(adapter.timeZoneLabel).toBe('NZ time');
    expect(adapter.subline).not.toContain('Pacific/Auckland');
    expect(adapter.onOpenItem).toBeUndefined();
    expect(adapter.mineLink?.href).toBe('/tasks?sources=referrals&assigned=me');
    expect(adapter.sourceFilters?.map((source) => source.key)).toEqual([
        'referral-follow-up',
        'referral-appointment',
    ]);
    expect(
        referralCalendarAdapter(
            { 'funding.view': true },
            vi.fn(),
        ).sourceFilters?.map((source) => source.key),
    ).toContain('referral-funding');
});

it('stops malformed pagination rather than rendering a partial calendar', async () => {
    const fetch = vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({ events: [], next_page: 1 }),
    });
    vi.stubGlobal('fetch', fetch);
    await expect(
        referralCalendarAdapter({}, vi.fn()).loadItems(range),
    ).rejects.toThrow('Unexpected calendar response');
    expect(fetch).toHaveBeenCalledTimes(1);
});
