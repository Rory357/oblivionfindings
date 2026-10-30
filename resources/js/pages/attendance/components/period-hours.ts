import type { OpenSession } from './shared';

/** Display estimate only. Closed sessions retain their existing clock-in-date accounting. */
export function openHoursInPeriod(
    session: OpenSession | null,
    start: string | undefined,
    end: string | undefined,
    now: Date,
): number | null {
    if (!session) return 0;
    const clockIn = Date.parse(session.clock_in_at);
    const periodStart = Date.parse(start ?? '');
    const periodEnd = Date.parse(end ?? '');
    const finish = now.getTime();
    if (
        ![clockIn, periodStart, periodEnd, finish].every(Number.isFinite) ||
        periodEnd <= periodStart
    )
        return null;
    const from = Math.max(clockIn, periodStart);
    const to = Math.min(finish, periodEnd);
    if (to <= from) return 0;
    const completeSession = from === clockIn && to === finish;
    const trackedClosedMinutes = session.breaks.reduce(
        (sum, event) => sum + (event.ended_at ? (event.minutes ?? 0) : 0),
        0,
    );
    // Legacy aggregate-only break time cannot be placed on one side of a day/week boundary.
    if (!completeSession && session.break_minutes > trackedClosedMinutes)
        return null;
    const intervals: [number, number][] = [];
    for (const event of session.breaks) {
        const eventStart = Date.parse(event.started_at ?? '');
        const eventEnd = event.ended_at ? Date.parse(event.ended_at) : finish;
        if (!Number.isFinite(eventStart) || !Number.isFinite(eventEnd))
            return null;
        const a = Math.max(from, eventStart);
        const b = Math.min(to, eventEnd);
        if (b > a) intervals.push([a, b]);
    }
    if (session.on_break && session.break_started_at) {
        const a = Math.max(from, Date.parse(session.break_started_at));
        if (!Number.isFinite(a)) return null;
        if (to > a) intervals.push([a, to]);
    }
    intervals.sort((a, b) => a[0] - b[0]);
    let breakMs = 0;
    let through = from;
    for (const [a, b] of intervals) {
        breakMs += Math.max(0, b - Math.max(a, through));
        through = Math.max(through, b);
    }
    if (completeSession)
        breakMs +=
            Math.max(0, session.break_minutes - trackedClosedMinutes) * 60_000;
    return Math.max(0, to - from - breakMs) / 3_600_000;
}
