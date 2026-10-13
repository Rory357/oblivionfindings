import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { useRosterCalendarFeed } from './use-roster-calendar-feed';

const requests: {
    url: string;
    signal: AbortSignal;
    resolve: (value: Response) => void;
    reject: (reason: Error) => void;
}[] = [];
const event = {
    id: 11,
    title: 'Duty',
    start: '2026-10-10T21:00:00Z',
    end: '2026-10-11T05:00:00Z',
    extendedProps: { status: 'scheduled' },
};
const initial = {
    url: '/operations/rostering/calendar/events?start=2026-10-01&end=2026-11-12',
    scope: 'worker5:sites2,7',
};
function harness() {
    return renderHook(({ url, scope }) => useRosterCalendarFeed(url, scope), {
        initialProps: initial,
    });
}
function response(data: unknown, ok = true) {
    return { ok, json: async () => data } as Response;
}
async function complete(index: number, data: unknown, ok = true) {
    await act(async () => requests[index].resolve(response(data, ok)));
}
beforeEach(() => {
    requests.length = 0;
    vi.stubGlobal(
        'fetch',
        vi.fn(
            (url: string, options: { signal: AbortSignal }) =>
                new Promise<Response>((resolve, reject) =>
                    requests.push({
                        url,
                        signal: options.signal,
                        resolve,
                        reject,
                    }),
                ),
        ),
    );
});
afterEach(() => vi.unstubAllGlobals());

it('distinguishes loading, assessed zero and assessed duties', async () => {
    const view = harness();
    expect(view.result.current.assessed).toBe(false);
    await complete(0, []);
    expect(view.result.current.assessed).toBe(true);
    expect(view.result.current.events).toEqual([]);
    act(() => view.result.current.retry());
    expect(view.result.current.assessed).toBe(false);
    await complete(1, [event]);
    expect(view.result.current.events).toEqual([event]);
});

it('withholds old shifts immediately when the displayed range changes', async () => {
    const view = harness();
    await complete(0, [event]);
    view.rerender({
        ...initial,
        url: '/operations/rostering/calendar/events?start=2026-11-01&end=2026-12-12',
    });
    expect(view.result.current.assessed).toBe(false);
    expect(view.result.current.events).toEqual([]);
    await complete(1, [], false);
    expect(view.result.current.loading).toBe(false);
    expect(view.result.current.assessed).toBe(false);
    expect(view.result.current.error).toMatch(/Retry/);
});

it('ignores an aborted older range response even if the transport resolves it later', async () => {
    const view = harness();
    view.rerender({ ...initial, url: initial.url + '&staff_id=9' });
    expect(requests[0].signal.aborted).toBe(true);
    await complete(1, [event]);
    await complete(0, [{ ...event, id: 22 }]);
    expect(view.result.current.events).toEqual([event]);
});

it.each([
    { data: [] },
    [null],
    [{ ...event, start: 'unknown time' }],
    [{ ...event, start: null }],
    [{ ...event, end: null }],
    [{ ...event, id: 'unknown-shift' }],
    [{ ...event, id: '0011' }],
    [{ ...event, end: '2026-10-10T20:00:00Z' }],
    [event, { ...event, id: 22, end: null }],
])(
    'rejects a malformed success as unavailable rather than an empty calendar: %j',
    async (data) => {
        const view = harness();
        await complete(0, data);
        expect(view.result.current.assessed).toBe(false);
        expect(view.result.current.error).toMatch(/Could not load/);
        expect(view.result.current.events).toEqual([]);
    },
);

it('removes prior records on account or selected-site scope change even with the same request URL', async () => {
    const view = harness();
    await complete(0, [event]);
    view.rerender({ ...initial, scope: 'worker9:sites3' });
    expect(view.result.current.events).toEqual([]);
    expect(view.result.current.assessed).toBe(false);
    await complete(1, []);
    expect(view.result.current.assessed).toBe(true);
});

it('retains an unavailable state on transport failure and allows an explicit retry', async () => {
    const view = harness();
    await act(async () => requests[0].reject(new Error('Network unavailable')));
    expect(view.result.current.error).toMatch(/Retry/);
    act(() => view.result.current.retry());
    expect(view.result.current.error).toBeNull();
    await complete(1, [event]);
    await waitFor(() => expect(view.result.current.assessed).toBe(true));
});

it('accepts a current marked coverage gap and a canonical numeric-string shift without silently dropping either', async () => {
    const view = harness();
    const gap = {
        ...event,
        id: 'coverage-gap-7-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        extendedProps: {
            event_type: 'coverage_gap',
            site_id: 2,
            coverage_rule_id: 7,
        },
    };
    await complete(0, [gap, { ...event, id: '11' }]);
    expect(view.result.current.assessed).toBe(true);
    expect(view.result.current.events).toHaveLength(2);
});

it('only applies optimistic updates to a successfully assessed current range', async () => {
    const view = harness();
    const update = vi.fn((rows) =>
        rows.map((row: typeof event) => ({ ...row, title: 'Moved duty' })),
    );
    act(() => view.result.current.updateEvents(update));
    expect(update).not.toHaveBeenCalled();
    await complete(0, [event]);
    act(() => view.result.current.updateEvents(update));
    expect(update).toHaveBeenCalledOnce();
    expect(view.result.current.events[0].title).toBe('Moved duty');
    act(() => view.result.current.retry());
    act(() => view.result.current.updateEvents(update));
    expect(update).toHaveBeenCalledOnce();
    expect(view.result.current.events).toEqual([]);
});

it.each(['range', 'scope', 'retry'])(
    'rejects an optimistic updater captured before a newer %s is assessed',
    async (change) => {
        const view = harness();
        await complete(0, [event]);
        const previousUpdater = view.result.current.updateEvents;
        if (change === 'range')
            view.rerender({ ...initial, url: initial.url + '&staff_id=9' });
        else if (change === 'scope')
            view.rerender({ ...initial, scope: 'worker9:sites3' });
        else act(() => view.result.current.retry());
        await complete(1, [{ ...event, id: 22 }]);
        const update = vi.fn(() => [event]);
        act(() => previousUpdater(update));
        expect(update).not.toHaveBeenCalled();
        expect(view.result.current.events.map((row) => row.id)).toEqual([22]);
    },
);
