import { afterEach, describe, expect, it, vi } from 'vitest';
import { createMyCalendarAdapter, myCalendarItem } from './my-calendar-adapter';

afterEach(() => vi.unstubAllGlobals());

describe('My Calendar data adapter', () => {
    const owner = { id: 7, name: 'Worker' };
    it('keeps a failed source explicit even when the remaining feed is empty', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => [],
                headers: new Headers({
                    'X-Calendar-Unavailable': JSON.stringify({
                        medication_round: 'unavailable',
                    }),
                }),
            }),
        );
        const result = await createMyCalendarAdapter(owner).loadItems({
            start: new Date('2026-09-01'),
            end: new Date('2026-10-01'),
        });
        expect(result).toEqual({
            events: [],
            availability: { medication_round: 'unavailable' },
        });
    });
    it('enables owned planning entries with a record identity and conflict version', () => {
        const item = myCalendarItem(
            {
                id: 'personal-4',
                title: 'Prepare notes',
                start: '2027-01-15T09:00:00Z',
                extendedProps: {
                    type: 'personal_task',
                    personal_entry: {
                        id: 4,
                        version: 3,
                        kind: 'task',
                        title: 'Prepare notes',
                        start_at: '2027-01-15T09:00:00Z',
                        end_at: null,
                        all_day: false,
                        status: 'scheduled',
                        description: 'My planning',
                        location: null,
                    },
                },
            },
            owner,
        );
        expect(item).toMatchObject({
            editable: true,
            recordId: 4,
            version: 3,
            group: 'manual',
            eventType: 'task',
            desc: 'My planning',
        });
    });
    it('keeps assigned work read-only and preserves times, details and the worker route', () => {
        const item = myCalendarItem(
            {
                id: 'shift-9',
                title: 'Work shift',
                start: '2026-09-26T22:00:00+12:00',
                end: '2026-09-27T07:00:00+13:00',
                extendedProps: {
                    type: 'shift',
                    link: '/my-day?shift=9',
                    timed_tasks: [
                        { scheduled_time: '23:00', label: 'Check in' },
                    ],
                },
            },
            owner,
        );
        expect(item).toMatchObject({
            source: 'shift',
            editable: false,
            owner,
            link: '/my-day?shift=9',
            desc: '23:00 Check in',
            end: '2026-09-27T07:00:00+13:00',
        });
    });
    it('uses the personal feed and propagates failed loads instead of reporting an empty calendar', async () => {
        const fetch = vi.fn().mockResolvedValue({ ok: false });
        vi.stubGlobal('fetch', fetch);
        const adapter = createMyCalendarAdapter(owner);
        await expect(
            adapter.loadItems({
                start: new Date('2026-09-01'),
                end: new Date('2026-10-01'),
            }),
        ).rejects.toThrow();
        expect(fetch.mock.calls[0][0]).toMatch(/^\/my-calendar\/events\?/);
        expect(adapter.mineLink?.href).toBe('/my-day');
        expect(adapter.allowSubscriptions).toBe(false);
    });
});

describe('Medication calendar request windows', () => {
    const owner = { id: 7, name: 'Worker' };
    it('loads the complete 75-day rail across NZ clock change without a rejected medication range or duplicate spanning shifts', async () => {
        const start = new Date('2026-08-22T00:00:00+12:00');
        const end = new Date('2026-11-05T23:00:00+13:00');
        const shared = {
            id: 'shift-1',
            title: 'Spanning shift',
            start: '2026-10-20T22:00:00+13:00',
            end: '2026-10-21T08:00:00+13:00',
            extendedProps: { type: 'shift', link: '/my-day?shift=1' },
        };
        const fetcher = vi.fn(async (url: string) => {
            const q = new URL(url, 'http://localhost').searchParams;
            const span =
                Date.parse(q.get('end')!) - Date.parse(q.get('start')!);
            return {
                ok: true,
                json: async () => [
                    shared,
                    {
                        ...shared,
                        id: 'med-' + q.get('start'),
                        extendedProps: {
                            type: 'medication_round',
                            link: '/meds/today',
                        },
                    },
                ],
                headers: new Headers(
                    span > 62 * 86400000
                        ? {
                              'X-Calendar-Unavailable':
                                  '{"medication_round":"unavailable"}',
                          }
                        : {},
                ),
            };
        });
        vi.stubGlobal('fetch', fetcher);
        const result = await createMyCalendarAdapter(owner).loadItems({
            start,
            end,
        });
        expect(result.availability).toEqual({});
        expect(result.events).toHaveLength(3);
        expect(
            result.events.filter((event) => event.id === 'shift-1'),
        ).toHaveLength(1);
        const ranges = fetcher.mock.calls.map(
            ([url]) => new URL(url, 'http://localhost').searchParams,
        );
        expect(ranges).toHaveLength(2);
        expect(ranges[0].get('start')).toBe(start.toISOString());
        expect(ranges[0].get('end')).toBe(ranges[1].get('start'));
        expect(ranges[1].get('end')).toBe(end.toISOString());
        expect(result.events[0].end).toBe(shared.end);
    });
    it('retains a partial-source warning from any window instead of clearing it with the final healthy response', async () => {
        const fetcher = vi
            .fn()
            .mockResolvedValueOnce({
                ok: true,
                json: async () => [],
                headers: new Headers({
                    'X-Calendar-Unavailable':
                        '{"medication_round":"unavailable"}',
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => [],
                headers: new Headers(),
            });
        vi.stubGlobal('fetch', fetcher);
        const result = await createMyCalendarAdapter(owner).loadItems({
            start: new Date('2026-08-22'),
            end: new Date('2026-11-05'),
        });
        expect(result.availability).toEqual({
            medication_round: 'unavailable',
        });
    });
    it('rejects a later failed window rather than displaying an incomplete empty-success result', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValueOnce({
                    ok: true,
                    json: async () => [],
                    headers: new Headers(),
                })
                .mockResolvedValueOnce({ ok: false }),
        );
        await expect(
            createMyCalendarAdapter(owner).loadItems({
                start: new Date('2026-08-22'),
                end: new Date('2026-11-05'),
            }),
        ).rejects.toThrow('Could not load your calendar.');
    });
});
