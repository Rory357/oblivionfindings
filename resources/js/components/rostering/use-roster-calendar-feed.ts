import { useCallback, useEffect, useState } from 'react';

export type RosterCalendarEvent = {
    id: number | string;
    title?: string;
    start: string;
    end: string;
    extendedProps?: Record<string, unknown>;
};
type Assessment = {
    key: string;
    state: 'loading' | 'ready' | 'unavailable';
    events: RosterCalendarEvent[];
};
const unavailable =
    'Could not load this calendar range. Retry to see its shifts.';

function validEvents(value: unknown): value is RosterCalendarEvent[] {
    if (!Array.isArray(value)) return false;
    return value.every((row: unknown) => {
        if (row === null || typeof row !== 'object' || Array.isArray(row))
            return false;
        const item = row as Record<string, unknown>;
        const extra = item.extendedProps;
        if (
            extra !== undefined &&
            (extra === null ||
                typeof extra !== 'object' ||
                Array.isArray(extra))
        )
            return false;
        const isGap =
            (extra as Record<string, unknown> | undefined)?.event_type ===
            'coverage_gap';
        const validId = isGap
            ? typeof item.id === 'string' &&
              /^coverage-gap-[1-9]\d*-[a-f0-9]{32}$/.test(item.id)
            : (typeof item.id === 'number' ||
                  (typeof item.id === 'string' &&
                      /^[1-9]\d*$/.test(item.id))) &&
              Number.isSafeInteger(Number(item.id)) &&
              Number(item.id) > 0;
        const date = (raw: unknown) =>
            typeof raw === 'string' &&
            raw.trim().length > 0 &&
            Number.isFinite(Date.parse(raw));
        return (
            validId &&
            date(item.start) &&
            date(item.end) &&
            Date.parse(item.end as string) >=
                Date.parse(item.start as string) &&
            (item.title === undefined || typeof item.title === 'string')
        );
    });
}

/** A successful empty array is assessed zero; missing/old/failed data is not. */
export function useRosterCalendarFeed(url: string, scope: string) {
    const [refresh, setRefresh] = useState(0);
    const key = JSON.stringify([url, scope, refresh]);
    const [assessment, setAssessment] = useState<Assessment>({
        key: '',
        state: 'loading',
        events: [],
    });
    const retry = useCallback(() => setRefresh((value) => value + 1), []);
    const updateEvents = useCallback(
        (update: (events: RosterCalendarEvent[]) => RosterCalendarEvent[]) => {
            setAssessment((current) =>
                current.key === key && current.state === 'ready'
                    ? { ...current, events: update(current.events) }
                    : current,
            );
        },
        [key],
    );
    useEffect(() => {
        let cancelled = false;
        const controller = new AbortController();
        setAssessment({ key, state: 'loading', events: [] });
        void (async () => {
            try {
                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('Calendar request failed');
                const data: unknown = await response.json();
                if (!validEvents(data))
                    throw new Error('Calendar response unavailable');
                if (!cancelled)
                    setAssessment({ key, state: 'ready', events: data });
            } catch {
                if (!cancelled)
                    setAssessment({ key, state: 'unavailable', events: [] });
            }
        })();
        return () => {
            cancelled = true;
            controller.abort();
        };
    }, [key, url]);
    const state = assessment.key === key ? assessment.state : 'loading';
    return {
        events: state === 'ready' ? assessment.events : [],
        assessed: state === 'ready',
        loading: state === 'loading',
        error: state === 'unavailable' ? unavailable : null,
        retry,
        updateEvents,
    };
}
