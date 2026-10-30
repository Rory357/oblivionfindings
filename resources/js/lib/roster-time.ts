import { WORKER_TIMEZONE } from './datetime';

export function rosterWallTime(
    value: string | Date,
    timeZone = WORKER_TIMEZONE,
): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(new Date(value));
    const get = (key: string) =>
        parts.find((part) => part.type === key)?.value ?? '';
    return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}:${get('second')}`;
}

/** Exact instants for a wall time: none in a gap, two when the clock repeats. */
export function rosterInstantOptions(
    wall: string,
    timeZone = WORKER_TIMEZONE,
): Date[] {
    const nominal = Date.parse(`${wall}Z`);
    const offsets = new Set(
        [-86400000, 0, 86400000].map((delta) => {
            const sample = nominal + delta;
            return (
                Date.parse(`${rosterWallTime(new Date(sample), timeZone)}Z`) -
                sample
            );
        }),
    );
    const matches = [...offsets]
        .map((offset) => new Date(nominal - offset))
        .filter((instant) => rosterWallTime(instant, timeZone) === wall);
    return matches.sort((a, b) => a.getTime() - b.getTime());
}

/** Resolve wall time without silently moving a shift across a daylight-saving gap. */
export function rosterInstant(wall: string, timeZone = WORKER_TIMEZONE): Date {
    const matches = rosterInstantOptions(wall, timeZone);
    if (matches.length !== 1)
        throw new Error(
            'This time is affected by a daylight-saving change. Open the shift to choose its exact times.',
        );
    return matches[0];
}

export function moveRosterShift(
    startsAt: string,
    endsAt: string,
    date: string,
    timeZone = WORKER_TIMEZONE,
) {
    const start = rosterInstant(
        `${date}T${rosterWallTime(startsAt, timeZone).slice(11)}`,
        timeZone,
    );
    const duration = Date.parse(endsAt) - Date.parse(startsAt);
    if (duration <= 0)
        throw new Error('Open this shift to check its start and end times.');
    return {
        starts_at: start.toISOString(),
        ends_at: new Date(start.getTime() + duration).toISOString(),
    };
}

/** Calendar dates touched by a half-open duty interval, including overnight continuation. */
export function rosterDayKeys(
    startsAt: string,
    endsAt: string,
    timeZone = WORKER_TIMEZONE,
): string[] {
    if (!(Date.parse(endsAt) > Date.parse(startsAt))) return [];
    const first = rosterWallTime(startsAt, timeZone).slice(0, 10);
    const last = rosterWallTime(
        new Date(Date.parse(endsAt) - 1),
        timeZone,
    ).slice(0, 10);
    const dates: string[] = [];
    for (
        let day = Date.parse(`${first}T12:00:00Z`),
            end = Date.parse(`${last}T12:00:00Z`);
        day <= end;
        day += 86400000
    )
        dates.push(new Date(day).toISOString().slice(0, 10));
    return dates;
}
