import type { MyDayMedDue, MyDayShiftTask } from './types';

export type StreamTaskItem = {
    kind: 'task';
    at: string;
    hr: number;
    clientId: number | null;
    data: MyDayShiftTask;
};

export type StreamMedItem = {
    kind: 'med';
    at: string;
    hr: number;
    clientId: number | null;
    data: MyDayMedDue;
};

export type StreamItem = StreamTaskItem | StreamMedItem;

/** Convert "HH:MM" → hours-as-decimal for ordering. Returns Infinity on parse failure. */
export function parseHourMinute(hhmm: string | null | undefined): number {
    if (!hhmm) return Number.POSITIVE_INFINITY;
    const [h, m] = hhmm.split(':').map((part) => Number(part));
    if (!Number.isFinite(h) || !Number.isFinite(m)) return Number.POSITIVE_INFINITY;
    return h + m / 60;
}

/** Returns "HH:MM" from an ISO datetime, or empty string when input is null. */
export function isoToHourMinute(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

interface BuildStreamArgs {
    tasks: MyDayShiftTask[];
    meds: MyDayMedDue[];
    /** Resident filter — null/undefined/'all' means everyone. */
    residentFilter?: number | string | null;
    /** Optional fallback when a task has no client_id (single-resident shifts). */
    fallbackClientId?: number | null;
}

export function buildStream({ tasks, meds, residentFilter, fallbackClientId = null }: BuildStreamArgs): StreamItem[] {
    const filterId = residentFilter === 'all' || residentFilter == null ? null : Number(residentFilter);

    const taskItems: StreamTaskItem[] = tasks
        .map((task) => {
            const clientId = task.client_id ?? fallbackClientId;
            const at = inferTaskTime(task);
            return {
                kind: 'task' as const,
                at,
                hr: parseHourMinute(at),
                clientId: clientId ?? null,
                data: task,
            };
        })
        .filter((item) => (filterId == null ? true : item.clientId === filterId));

    const medItems: StreamMedItem[] = meds
        .map((med) => {
            const at = isoToHourMinute(med.scheduled_for);
            return {
                kind: 'med' as const,
                at,
                hr: parseHourMinute(at),
                clientId: med.client_id,
                data: med,
            };
        })
        .filter((item) => (filterId == null ? true : item.clientId === filterId));

    return [...taskItems, ...medItems].sort((a, b) => a.hr - b.hr);
}

/** Groups a stream by "HH:MM" preserving the relative order returned by buildStream. */
export function groupByTime(stream: StreamItem[]): Array<{ time: string; items: StreamItem[] }> {
    const map = new Map<string, StreamItem[]>();
    for (const item of stream) {
        const key = item.at || '—';
        const bucket = map.get(key);
        if (bucket) bucket.push(item);
        else map.set(key, [item]);
    }
    return Array.from(map.entries()).map(([time, items]) => ({ time, items }));
}

/** Tasks don't always carry an `at` field in the existing payload; some controllers set it on
 *  custom keys. Resolve through the most common shapes, defaulting to "" when unknown. */
function inferTaskTime(task: MyDayShiftTask & { at?: string; scheduled_for?: string }): string {
    if (typeof (task as { at?: string }).at === 'string') return (task as { at: string }).at;
    const scheduled = (task as { scheduled_for?: string }).scheduled_for;
    if (typeof scheduled === 'string') return isoToHourMinute(scheduled);
    return '';
}

/**
 * "HH:MM" representation of the current wall-clock time, used by the NowRule.
 */
export function nowHourMinute(now: Date = new Date()): string {
    return `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
}

/**
 * Computes the index BEFORE which the NowRule should be inserted in a sorted
 * list of time-grouped buckets. Returns -1 when "now" is past the last bucket
 * (the rule sits after everything).
 */
export function nowRuleIndex(buckets: Array<{ time: string }>, now: string): number {
    const nowHr = parseHourMinute(now);
    for (let i = 0; i < buckets.length; i++) {
        if (parseHourMinute(buckets[i].time) >= nowHr) return i;
    }
    return -1;
}
