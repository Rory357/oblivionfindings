import { afterEach, describe, expect, it, vi } from 'vitest';
import { createMyCalendarAdapter, myCalendarItem } from './my-calendar-adapter';

afterEach(() => vi.unstubAllGlobals());

describe('My Calendar data adapter', () => {
    const owner = { id: 7, name: 'Worker' };
    it('keeps a failed source explicit even when the remaining feed is empty', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockResolvedValue({
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
